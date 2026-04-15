<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Service\DiscogsService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class MediaController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaService $mediaService,
        private readonly DiscogsService $discogsService,
        private readonly IUserSession $userSession,
        private readonly PlaylistMapper $playlistMapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly CrateShareMapper $shareMapper,
        private readonly IAppDataFactory $appDataFactory,
    ) {
        parent::__construct($appName, $request);
    }

    private function userId(): string
    {
        return $this->userSession->getUser()->getUID();
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        return new DataResponse($this->mediaService->findAll($this->userId()));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        return new DataResponse($this->mediaService->find($id, $this->userId()));
    }

    #[NoAdminRequired]
    public function create(
        string $title,
        string $artist,
        string $format,
        ?int $year = null,
        ?string $barcode = null,
        ?string $notes = null,
        string $status = 'owned',
        ?string $discogsId = null,
        ?string $artworkPath = null,
        ?string $label = null,
        ?string $country = null,
    ): DataResponse {
        return new DataResponse(
            $this->mediaService->create(
                $this->userId(),
                $title,
                $artist,
                $format,
                $year,
                $barcode,
                $notes,
                $status,
                $discogsId,
                $artworkPath,
                $label,
                $country,
            )
        );
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        string $title,
        string $artist,
        string $format,
        ?int $year = null,
        ?string $barcode = null,
        ?string $notes = null,
        string $status = 'owned',
        ?string $discogsId = null,
        ?string $artworkPath = null,
        ?string $label = null,
        ?string $country = null,
    ): DataResponse {
        return new DataResponse(
            $this->mediaService->update(
                $id,
                $this->userId(),
                $title,
                $artist,
                $format,
                $year,
                $barcode,
                $notes,
                $status,
                $discogsId,
                $artworkPath,
                $label,
                $country,
            )
        );
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse
    {
        $this->mediaService->delete($id, $this->userId());
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    public function destroyAll(): DataResponse
    {
        $userId = $this->userId();

        // Collect item IDs before deletion so we can purge artwork files
        $items = $this->mediaService->findAll($userId);
        $itemIds = array_map(fn($i) => $i->getId(), $items);

        // Delete shares this user has created (their own data)
        // Shares received from others are not touched — they belong to the sharer
        $this->shareMapper->deleteAllByOwner($userId);

        // Playlist items (delete per-playlist to avoid subquery issues), then playlists
        $playlists = $this->playlistMapper->findAll($userId);
        foreach ($playlists as $playlist) {
            $this->playlistItemMapper->deleteByPlaylist($playlist->getId());
        }
        $this->playlistMapper->deleteAllByUser($userId);

        // Media items
        $this->mediaService->deleteAll($userId);

        // Artwork files stored in appdata (only for this user's items)
        if (!empty($itemIds)) {
            try {
                $folder = $this->appDataFactory->get('crate')->getFolder('artwork');
                foreach ($itemIds as $id) {
                    foreach (['.jpg', '.png', '.webp', '.gif'] as $ext) {
                        try {
                            $folder->getFile('artwork_' . $id . $ext)->delete();
                        } catch (NotFoundException) {
                        }
                    }
                }
            } catch (NotFoundException) {
            }
        }

        return new DataResponse([]);
    }

    /**
     * Enrich a media item with full Discogs release details and artist profile.
     *
     * Fetches /releases/{discogsId} and, if an artist ID is returned,
     * also /artists/{artistId}. The results are persisted to the item.
     *
     * POST /api/v1/media/{id}/enrich
     */
    #[NoAdminRequired]
    public function enrich(int $id): DataResponse
    {
        $item = $this->mediaService->find($id, $this->userId());

        try {
            // If no Discogs ID is stored, search by artist + title and pick the best match.
            if (empty($item->getDiscogsId())) {
                $query = trim($item->getArtist() . ' ' . $item->getTitle());
                $results = $this->discogsService->search($this->userId(), $query);
                if (empty($results)) {
                    return new DataResponse(
                        ['error' => 'No Discogs match found for this item.'],
                        Http::STATUS_NOT_FOUND,
                    );
                }

                // Prefer a result whose mapped format matches the item's stored format.
                // This avoids picking a Vinyl pressing when the item is a CD, etc.
                $itemFormat = $item->getFormat();
                $matching = array_values(array_filter(
                    $results,
                    fn(array $r) => ($r['format'] ?? '') === $itemFormat,
                ));
                $best = !empty($matching) ? $matching[0] : $results[0];

                $discogsId = $best['discogsId'] ?? '';
                if ($discogsId === '') {
                    return new DataResponse(
                        ['error' => 'No Discogs match found for this item.'],
                        Http::STATUS_NOT_FOUND,
                    );
                }
                $item = $this->mediaService->patchDiscogsId($id, $this->userId(), $discogsId);
            }

            $release = $this->discogsService->getRelease($this->userId(), $item->getDiscogsId());
            if (empty($release)) {
                return new DataResponse(
                    ['error' => 'Could not fetch release from Discogs. Check your token.'],
                    Http::STATUS_BAD_GATEWAY,
                );
            }

            // Fetch artist profile if a Discogs artist ID is available
            $artist = [];
            $artistId = $release['discogsArtistId'] ?? $item->getDiscogsArtistId();
            if (!empty($artistId)) {
                $artist = $this->discogsService->getArtist($this->userId(), $artistId);
            }

            $updated = $this->mediaService->applyReleaseData($id, $this->userId(), $release, $artist);

            return new DataResponse($updated);
        } catch (\OCA\Crate\Exception\DiscogsRateLimitException) {
            return new DataResponse(
                ['error' => 'Discogs rate limit exceeded. The queue will retry automatically.'],
                Http::STATUS_TOO_MANY_REQUESTS,
            );
        }
    }

    /**
     * Remove all Discogs-sourced enrichment data from an item.
     * Keeps title, artist, format, year, notes, status and artwork.
     *
     * DELETE /api/v1/media/{id}/enrich
     */
    #[NoAdminRequired]
    public function stripEnrich(int $id): DataResponse
    {
        $updated = $this->mediaService->stripEnrichment($id, $this->userId());
        return new DataResponse($updated);
    }
}
