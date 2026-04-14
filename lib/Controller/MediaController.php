<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\DiscogsService;
use OCA\Crate\Service\MediaService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
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
        $this->mediaService->deleteAll($this->userId());
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

        if (empty($item->getDiscogsId())) {
            return new DataResponse(
                ['error' => 'Item has no Discogs ID — search Discogs first to link a release.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $release = $this->discogsService->getRelease($this->userId(), $item->getDiscogsId());
        if (empty($release)) {
            return new DataResponse(
                ['error' => 'Could not fetch release from Discogs. Check your token is configured.'],
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
    }
}
