<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Dto\MediaItemData;
use OCA\Crate\Service\DiscogsService;
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\OpenLibraryService;
use OCA\Crate\Service\RawgService;
use OCA\Crate\Service\TmdbService;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class MediaController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaService $mediaService,
        private readonly DiscogsService $discogsService,
        private readonly TmdbService $tmdbService,
        private readonly OpenLibraryService $openLibraryService,
        private readonly RawgService $rawgService,
        private readonly MarketValueService $marketValueService,
        private readonly IUserSession $userSession,
        private readonly PlaylistMapper $playlistMapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly CrateShareMapper $shareMapper,
        private readonly IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    private const VALID_CATEGORIES = ['music', 'film', 'book', 'game'];

    #[NoAdminRequired]
    public function index(
        ?string $status = null,
        ?string $category = null,
        ?string $updatedSince = null,
        int $limit = 50,
        int $offset = 0,
    ): DataResponse {
        // Legacy callers (web app) get the flat array; API callers using
        // limit/offset/updatedSince get wrapped pagination metadata.
        $isPaginated = $this->request->getParam('limit') !== null
            || $this->request->getParam('offset') !== null
            || $this->request->getParam('updatedSince') !== null
            || $this->request->getParam('status') !== null;

        $offset = max(0, $offset);

        if ($isPaginated) {
            $result = $this->mediaService->findPaginated(
                $this->userId(),
                $status,
                $category,
                $updatedSince,
                $limit,
                $offset,
            );
            return new DataResponse([
                'items'  => $result['items'],
                'total'  => $result['total'],
                'limit'  => $limit,
                'offset' => $offset,
            ]);
        }

        return new DataResponse($this->mediaService->findAll($this->userId(), $category));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        return new DataResponse($this->mediaService->find($id, $this->userId()));
    }

    private const VALID_STATUSES = ['owned', 'wanted'];

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
        string $category = 'music',
    ): DataResponse {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return new DataResponse(['error' => 'Invalid status'], Http::STATUS_BAD_REQUEST);
        }
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return new DataResponse(['error' => 'Invalid category'], Http::STATUS_BAD_REQUEST);
        }
        $data = new MediaItemData(
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
            $category,
        );
        return new DataResponse($this->mediaService->create($this->userId(), $data));
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
        string $category = 'music',
    ): DataResponse {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return new DataResponse(['error' => 'Invalid status'], Http::STATUS_BAD_REQUEST);
        }
        if (!in_array($category, self::VALID_CATEGORIES, true)) {
            return new DataResponse(['error' => 'Invalid category'], Http::STATUS_BAD_REQUEST);
        }
        $data = new MediaItemData(
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
            $category,
        );
        return new DataResponse($this->mediaService->update($id, $this->userId(), $data));
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

        // Delete playlist shares, playlist items, then playlists
        $playlists = $this->playlistMapper->findAll($userId);
        foreach ($playlists as $playlist) {
            $this->shareMapper->deleteByShareable('playlist', $playlist->getId());
            $this->playlistItemMapper->deleteByPlaylist($playlist->getId());
        }
        $this->playlistMapper->deleteAllByUser($userId);

        // Delete all media items with full cleanup (artwork, shares, playlist refs)
        $this->mediaService->deleteAllForUser($userId);

        return new DataResponse([]);
    }

    /**
     * Enrich a media item using the appropriate service for its category.
     * Music → Discogs; Film → TMDB; Book → Open Library; Game → RAWG.
     *
     * POST /api/v1/media/{id}/enrich
     */
    #[NoAdminRequired]
    public function enrich(int $id): DataResponse
    {
        $item     = $this->mediaService->find($id, $this->userId());
        $category = $item->getCategory();

        if ($category === 'film') {
            return $this->enrichFilm($id, $item);
        }
        if ($category === 'book') {
            return $this->enrichBook($id, $item);
        }
        if ($category === 'game') {
            return $this->enrichGame($id, $item);
        }

        // Default: music via Discogs
        return $this->enrichMusic($id, $item);
    }

    private function enrichMusic(int $id, \OCA\Crate\Db\MediaItem $item): DataResponse
    {
        try {
            if (empty($item->getDiscogsId())) {
                $query   = trim($item->getArtist() . ' ' . $item->getTitle());
                $results = $this->discogsService->search($this->userId(), $query);
                if (empty($results)) {
                    return new DataResponse(
                        ['error' => 'No Discogs match found for this item.'],
                        Http::STATUS_NOT_FOUND,
                    );
                }

                $itemFormat = $item->getFormat();
                $matching   = array_values(array_filter(
                    $results,
                    fn(array $r) => ($r['format'] ?? '') === $itemFormat,
                ));
                $best      = !empty($matching) ? $matching[0] : $results[0];
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

            $artist   = [];
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

    private function enrichFilm(int $id, \OCA\Crate\Db\MediaItem $item): DataResponse
    {
        $tmdbId = $item->getDiscogsId();

        if (empty($tmdbId)) {
            $query   = trim($item->getTitle());
            $results = $this->tmdbService->search($this->userId(), $query);
            if (empty($results)) {
                return new DataResponse(
                    ['error' => 'No TMDB match found. Add a TMDB token in Settings.'],
                    Http::STATUS_NOT_FOUND,
                );
            }
            $tmdbId = (string)($results[0]['tmdbId'] ?? '');
        }

        if ($tmdbId === '') {
            return new DataResponse(['error' => 'No TMDB ID available.'], Http::STATUS_NOT_FOUND);
        }

        $movie = $this->tmdbService->getMovie($this->userId(), $tmdbId);
        if (empty($movie)) {
            return new DataResponse(
                ['error' => 'Could not fetch film from TMDB. Check your token.'],
                Http::STATUS_BAD_GATEWAY,
            );
        }

        $updated = $this->mediaService->applyTmdbData($id, $this->userId(), $movie);
        return new DataResponse($updated);
    }

    private function enrichBook(int $id, \OCA\Crate\Db\MediaItem $item): DataResponse
    {
        $workKey = $item->getDiscogsId();

        if (empty($workKey)) {
            $query   = trim($item->getArtist() . ' ' . $item->getTitle());
            $results = $this->openLibraryService->search($query);
            if (empty($results)) {
                return new DataResponse(
                    ['error' => 'No Open Library match found.'],
                    Http::STATUS_NOT_FOUND,
                );
            }
            $workKey = (string)($results[0]['workKey'] ?? '');
            $doc     = $results[0];
        } else {
            $doc = ['workKey' => $workKey];
        }

        if ($workKey === '') {
            return new DataResponse(['error' => 'No Open Library work key available.'], Http::STATUS_NOT_FOUND);
        }

        $work    = $this->openLibraryService->getWork($workKey);
        $updated = $this->mediaService->applyOpenLibraryData($id, $this->userId(), $doc, $work);
        return new DataResponse($updated);
    }

    private function enrichGame(int $id, \OCA\Crate\Db\MediaItem $item): DataResponse
    {
        $rawgId = $item->getDiscogsId();

        if (empty($rawgId)) {
            $query   = trim($item->getTitle());
            $results = $this->rawgService->search($this->userId(), $query);
            if (empty($results)) {
                return new DataResponse(
                    ['error' => 'No RAWG match found. Add a RAWG API key in Settings.'],
                    Http::STATUS_NOT_FOUND,
                );
            }
            $rawgId = (string)($results[0]['rawgId'] ?? '');
        }

        if ($rawgId === '') {
            return new DataResponse(['error' => 'No RAWG ID available.'], Http::STATUS_NOT_FOUND);
        }

        $game = $this->rawgService->getGame($this->userId(), $rawgId);
        if (empty($game)) {
            return new DataResponse(
                ['error' => 'Could not fetch game from RAWG. Check your API key.'],
                Http::STATUS_BAD_GATEWAY,
            );
        }

        $updated = $this->mediaService->applyRawgData($id, $this->userId(), $game);
        return new DataResponse($updated);
    }

    /**
     * Return the IDs of items that have a discogsId and can have market values fetched.
     * The Android app uses this to queue individual fetchMarketValue calls.
     * POST /api/v1/market-value/refresh-all
     */
    #[NoAdminRequired]
    public function refreshAllMarketValues(): DataResponse
    {
        $userId   = $this->userId();
        $currency = $this->config->getUserValue($userId, 'crate', 'market_currency', 'GBP');
        $items    = $this->mediaService->findAll($userId);
        $eligible = array_values(
            array_filter($items, fn($i) => $i->getDiscogsId() !== null && $i->getDiscogsId() !== '')
        );
        return new DataResponse([
            'currency' => $currency,
            'total'    => count($eligible),
            'itemIds'  => array_map(fn($i) => $i->getId(), $eligible),
        ]);
    }

    #[NoAdminRequired]
    public function fetchMarketValue(int $id, string $currency = 'GBP'): DataResponse
    {
        try {
            $updated = $this->marketValueService->fetchAndStore($id, $this->userId(), $currency);
            if ($updated === null) {
                return new DataResponse(
                    ['error' => 'Item has no Discogs ID — enrich it first.'],
                    Http::STATUS_UNPROCESSABLE_ENTITY,
                );
            }
            return new DataResponse($updated);
        } catch (\OCA\Crate\Exception\DiscogsRateLimitException) {
            return new DataResponse(
                ['error' => 'Discogs rate limit exceeded. Try again shortly.'],
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
