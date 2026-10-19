<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Exception\DiscogsRateLimitException;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;

class MarketValueService
{
    private const API_BASE   = 'https://api.discogs.com';
    private const USER_AGENT = 'CrateNextcloudApp/0.4 +https://github.com/megamaced/crate';

    /** Upper bound for persisted market values — guards against upstream typos. */
    private const MAX_MARKET_VALUE = 1_000_000.0;

    public const SUPPORTED_CURRENCIES = [
        'GBP', 'USD', 'EUR', 'CAD', 'AUD', 'JPY',
        'CHF', 'MXN', 'BRL', 'NZD', 'SEK', 'ZAR',
    ];

    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly PriceChartingService $priceChartingService,
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * Fetch market value(s) for an item and persist them.
     * Dispatches to PriceCharting for game/comic, Discogs for music.
     * Film/book have no market-value source — defensively returns null
     * so a stray refresh-all call can't store unrelated Discogs prices
     * against their (TMDB / Open Library) enrichment ids.
     * Returns null when no enrichment ID is available or fetch fails.
     */
    public function fetchAndStore(int $id, string $userId, string $currency): ?MediaItem
    {
        $item     = $this->mapper->findWritableForUser($id, $userId);
        $category = $item->getCategory();

        if (!CrateCategories::hasMarketValue($category)) {
            return null;
        }

        if (CrateCategories::usesPriceCharting($category)) {
            return $this->fetchAndStorePriceCharting($item, $userId);
        }

        return $this->fetchAndStoreDiscogs($item, $userId, $currency);
    }

    private function fetchAndStoreDiscogs(MediaItem $item, string $userId, string $currency): ?MediaItem
    {
        if (empty($item->getDiscogsId())) {
            return null;
        }
        $pricedAs = PricingIdentity::of($item);

        $currency = in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true)
            ? strtoupper($currency)
            : 'GBP';

        $stats = $this->fetchStats($userId, $item->getDiscogsId(), $currency);
        if ($stats === null) {
            return null;
        }

        return $this->storeIfStillPricedAs($item, $userId, $pricedAs, [
            'value'    => $this->clampValue($stats['value']),
            'loose'    => null,
            'new'      => null,
            'currency' => $stats['currency'],
        ]);
    }

    private function fetchAndStorePriceCharting(MediaItem $item, string $userId): ?MediaItem
    {
        $pricedAs = PricingIdentity::of($item);
        $query  = trim($item->getTitle());
        if ($query === '') {
            return null;
        }

        // A game's format is its platform, which tells one release from
        // another: the title alone matches a dozen products at unrelated
        // prices. A comic is matched on series, issue and year instead.
        $prices = $item->getCategory() === CrateCategories::COMIC
            ? $this->priceChartingService->searchAndFetchComicPrices(
                $userId,
                $query,
                $item->getFormat(),
                $item->getYear(),
            )
            : $this->priceChartingService->searchAndFetchPrices($userId, $query, $item->getFormat());
        if ($prices === null) {
            return null;
        }

        return $this->storeIfStillPricedAs($item, $userId, $pricedAs, [
            'value'    => $this->clampValue($prices['cib']),
            'loose'    => $this->clampValue($prices['loose']),
            'new'      => $this->clampValue($prices['new']),
            'currency' => 'USD',
        ]);
    }

    /**
     * Store a looked-up price, unless the item stopped being what was looked
     * up while the request was out. A lookup takes seconds — PriceCharting's
     * pacing alone spaces its two calls by one — and an edit in that window
     * (release 101 to 102, say) has already retired the old price; writing
     * this one would put release 101's value back on release 102.
     *
     * The row is locked and re-read before the comparison, so an edit that
     * commits first is seen, and one that commits later waits for this write
     * and then retires it. The current row is returned either way, so a client
     * shows what the item now is.
     *
     * @param list<string|int|null> $pricedAs PricingIdentity::of() at lookup time
     * @param array{value: float|null, loose: float|null, new: float|null, currency: string} $price
     */
    private function storeIfStillPricedAs(MediaItem $item, string $userId, array $pricedAs, array $price): MediaItem
    {
        $this->db->beginTransaction();
        try {
            $this->mapper->lockRow($item->getId());
            $current = $this->mapper->findWritableForUser($item->getId(), $userId);
            if (PricingIdentity::of($current) === $pricedAs) {
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $current->setMarketValue($price['value']);
                $current->setMarketValueLoose($price['loose']);
                $current->setMarketValueNew($price['new']);
                $current->setMarketValueCurrency($price['currency']);
                $current->setMarketValueFetchedAt($now);
                $current->setUpdatedAt($now);
                $current = $this->mapper->update($current);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $current;
    }

    /**
     * Clamp a reported market value into a sane range. Negatives and
     * over-large numbers are rejected as null rather than being stored.
     */
    private function clampValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $f = (float) $value;
        if ($f < 0 || $f > self::MAX_MARKET_VALUE || !is_finite($f)) {
            return null;
        }
        return $f;
    }

    /**
     * Hit /marketplace/stats/{releaseId}?curr_abbr={currency} and return
     * ['value' => float, 'currency' => string] or null on failure.
     *
     * The endpoint is public but we include the user token when available for
     * higher rate-limit headroom.
     *
     * @return array{value: float, currency: string}|null
     */
    private function fetchStats(string $userId, string $releaseId, string $currency): ?array
    {
        $token = (string) ($this->credentialsManager->retrieve($userId, 'crate/discogs_token') ?? '');

        $headers = [
            'User-Agent' => self::USER_AGENT,
            'Accept'     => 'application/json',
        ];
        if ($token !== '') {
            $headers['Authorization'] = 'Discogs token=' . $token;
        }

        $client = $this->clientService->newClient();
        try {
            $response = $client->get(
                self::API_BASE . '/marketplace/stats/' . rawurlencode($releaseId),
                [
                    'query'   => ['curr_abbr' => $currency],
                    'headers' => $headers,
                    'timeout' => 10,
                ],
            );
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                throw new DiscogsRateLimitException('Discogs rate limit exceeded.', 429, $e);
            }
            return null;
        }

        $body = json_decode($response->getBody(), true) ?? [];

        $lowestPrice = $body['lowest_price'] ?? null;
        if (!isset($lowestPrice['value'])) {
            return null;
        }

        return [
            'value'    => (float)$lowestPrice['value'],
            'currency' => (string)($lowestPrice['currency'] ?? $currency),
        ];
    }
}
