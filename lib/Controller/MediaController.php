<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\CrateCategories;
use OCA\Crate\Dto\MediaItemData;
use OCA\Crate\Service\EnrichmentService;
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\MediaService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class MediaController extends OCSController
{
    use UsesAuthenticatedUser;

    /** Max offset accepted by paginated endpoints. Protects against DoS via absurd offsets. */
    private const MAX_OFFSET = 100000;
    /** Max limit accepted by paginated endpoints. */
    private const MAX_LIMIT = 200;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaService $mediaService,
        private readonly EnrichmentService $enrichmentService,
        private readonly MarketValueService $marketValueService,
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(
        ?string $status = null,
        ?string $category = null,
        ?string $updatedSince = null,
        int $limit = 50,
        int $offset = 0,
        ?bool $paginated = null,
    ): DataResponse {
        // Explicit flag or inferred from presence of pagination-related params.
        $isPaginated = $paginated === true
            || ($paginated === null && (
                $this->request->getParam('limit') !== null
                || $this->request->getParam('offset') !== null
                || $this->request->getParam('updatedSince') !== null
                || $this->request->getParam('status') !== null
            ));

        $offset = max(0, min($offset, self::MAX_OFFSET));
        $limit  = max(1, min($limit, self::MAX_LIMIT));

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
                'items'   => $result['items'],
                'total'   => $result['total'],
                'limit'   => $limit,
                'offset'  => $offset,
                'wipedAt' => $this->mediaService->getWipedAt($this->userId()),
            ]);
        }

        return new DataResponse($this->mediaService->findAll($this->userId(), $category));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse
    {
        // Read-path: owner OR sharee (via per-album / library / category share).
        // Writes still go through find() so sharees can't mutate items.
        return new DataResponse($this->mediaService->findVisible($id, $this->userId()));
    }

    #[NoAdminRequired]
    public function create(
        string $title,
        string $artist,
        string $mediaFormat,
        ?int $year = null,
        ?string $barcode = null,
        ?string $notes = null,
        string $status = CrateCategories::STATUS_OWNED,
        ?string $discogsId = null,
        ?string $artworkPath = null,
        ?string $label = null,
        ?string $country = null,
        string $category = CrateCategories::MUSIC,
        ?float $purchasePrice = null,
        ?string $purchasePriceCurrency = null,
    ): DataResponse {
        if (!CrateCategories::isStatus($status)) {
            return new DataResponse(['error' => 'Invalid status'], Http::STATUS_BAD_REQUEST);
        }
        if (!CrateCategories::isCategory($category)) {
            return new DataResponse(['error' => 'Invalid category'], Http::STATUS_BAD_REQUEST);
        }
        $priceResult = self::normalisePurchasePrice($purchasePrice, $purchasePriceCurrency);
        if (isset($priceResult['error'])) {
            return new DataResponse(['error' => $priceResult['error']], Http::STATUS_BAD_REQUEST);
        }
        $data = new MediaItemData(
            $title,
            $artist,
            $mediaFormat,
            $year,
            $barcode,
            $notes,
            $status,
            $discogsId,
            $artworkPath,
            $label,
            $country,
            $category,
            $priceResult['price'],
            $priceResult['currency'],
        );
        return new DataResponse($this->mediaService->create($this->userId(), $data));
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        string $title,
        string $artist,
        string $mediaFormat,
        ?int $year = null,
        ?string $barcode = null,
        ?string $notes = null,
        string $status = CrateCategories::STATUS_OWNED,
        ?string $discogsId = null,
        ?string $artworkPath = null,
        ?string $label = null,
        ?string $country = null,
        ?string $category = null,
        ?float $purchasePrice = null,
        ?string $purchasePriceCurrency = null,
    ): DataResponse {
        if (!CrateCategories::isStatus($status)) {
            return new DataResponse(['error' => 'Invalid status'], Http::STATUS_BAD_REQUEST);
        }
        if ($category !== null && !CrateCategories::isCategory($category)) {
            return new DataResponse(['error' => 'Invalid category'], Http::STATUS_BAD_REQUEST);
        }
        $priceResult = self::normalisePurchasePrice($purchasePrice, $purchasePriceCurrency);
        if (isset($priceResult['error'])) {
            return new DataResponse(['error' => $priceResult['error']], Http::STATUS_BAD_REQUEST);
        }
        $data = new MediaItemData(
            $title,
            $artist,
            $mediaFormat,
            $year,
            $barcode,
            $notes,
            $status,
            $discogsId,
            $artworkPath,
            $label,
            $country,
            $category,
            $priceResult['price'],
            $priceResult['currency'],
        );
        return new DataResponse($this->mediaService->update($id, $this->userId(), $data));
    }

    /**
     * Normalise the purchase-price pair: bound the value to a sane range, allow
     * either null (clear) or a non-negative number, and require the currency to
     * sit on the shared MarketValueService allowlist when a price is set.
     *
     * Returns ['price' => ?float, 'currency' => ?string] on success, or
     * ['error' => string] for the controller to surface as a 400.
     *
     * @return array{price?: ?float, currency?: ?string, error?: string}
     */
    public static function normalisePurchasePrice(?float $price, ?string $currency): array
    {
        if ($price === null) {
            // Treat a stray currency without a price as "clear both".
            return ['price' => null, 'currency' => null];
        }
        if ($price < 0 || $price > 1_000_000) {
            return ['error' => 'purchasePrice out of range'];
        }
        $currency = $currency !== null ? strtoupper(trim($currency)) : null;
        if ($currency === null || $currency === '') {
            return ['error' => 'purchasePriceCurrency is required when purchasePrice is set'];
        }
        if (!in_array($currency, MarketValueService::SUPPORTED_CURRENCIES, true)) {
            return ['error' => 'Unsupported purchasePriceCurrency'];
        }
        return ['price' => $price, 'currency' => $currency];
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse
    {
        $this->mediaService->delete($id, $this->userId());
        return new DataResponse([]);
    }

    /**
     * Wipe selected scopes of a user's data.
     *
     * `scopes` is a comma-separated query param; allowed values are the five
     * category names (music / film / book / game / comic) and the literal
     * 'playlists' (which also removes playlist shares). With no scopes
     * parameter the entire user collection + playlists is wiped, preserving
     * the original behaviour of this endpoint.
     */
    #[NoAdminRequired]
    public function destroyAll(?string $scopes = null): DataResponse
    {
        if ($scopes === null || $scopes === '') {
            $this->mediaService->wipeUserData($this->userId());
            return new DataResponse([]);
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $scopes))));
        $valid     = array_merge(CrateCategories::ALL, ['playlists']);
        $unknown   = array_values(array_diff($requested, $valid));

        if (!empty($unknown)) {
            return new DataResponse(
                ['error' => 'Unknown wipe scope(s): ' . implode(', ', $unknown)],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $this->mediaService->wipeScopes($this->userId(), $requested);
        return new DataResponse(['scopes' => $requested]);
    }

    /**
     * Enrich a media item using the appropriate service for its category.
     * Music → Discogs; Film → TMDB; Book → Open Library; Game → RAWG;
     * Comic → ComicVine.
     *
     * POST /api/v1/media/{id}/enrich
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function enrich(int $id): DataResponse
    {
        $result = $this->enrichmentService->enrich($id, $this->userId());
        if ($result->isOk()) {
            return new DataResponse($result->item);
        }
        return new DataResponse(['error' => $result->error], $result->status);
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
        $itemIds  = $this->mediaService->findIdsWithEnrichmentForUser($userId);
        return new DataResponse([
            'currency' => $currency,
            'total'    => count($itemIds),
            'itemIds'  => $itemIds,
        ]);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
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
