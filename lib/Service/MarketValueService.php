<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Exception\DiscogsRateLimitException;
use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;

class MarketValueService
{
    private const API_BASE   = 'https://api.discogs.com';
    private const USER_AGENT = 'CrateNextcloudApp/0.1 +https://gitea.macecloud.co.uk/macebox/crate';

    public const SUPPORTED_CURRENCIES = [
        'GBP', 'USD', 'EUR', 'CAD', 'AUD', 'JPY',
        'CHF', 'MXN', 'BRL', 'NZD', 'SEK', 'ZAR',
    ];

    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly PriceChartingService $priceChartingService,
    ) {
    }

    /**
     * Fetch market value(s) for an item and persist them.
     * Dispatches to PriceCharting for game/comic, Discogs for music.
     * Returns null when no enrichment ID is available or fetch fails.
     */
    public function fetchAndStore(int $id, string $userId, string $currency): ?MediaItem
    {
        $item     = $this->mapper->findByUser($id, $userId);
        $category = $item->getCategory();

        if (in_array($category, ['game', 'comic'], true)) {
            return $this->fetchAndStorePriceCharting($item, $userId);
        }

        return $this->fetchAndStoreDiscogs($item, $userId, $currency);
    }

    private function fetchAndStoreDiscogs(MediaItem $item, string $userId, string $currency): ?MediaItem
    {
        if (empty($item->getDiscogsId())) {
            return null;
        }

        $currency = in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true)
            ? strtoupper($currency)
            : 'GBP';

        $stats = $this->fetchStats($userId, $item->getDiscogsId(), $currency);
        if ($stats === null) {
            return null;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $item->setMarketValue($stats['value']);
        $item->setMarketValueLoose(null);
        $item->setMarketValueNew(null);
        $item->setMarketValueCurrency($stats['currency']);
        $item->setMarketValueFetchedAt($now);
        $item->setUpdatedAt($now);

        return $this->mapper->update($item);
    }

    private function fetchAndStorePriceCharting(MediaItem $item, string $userId): ?MediaItem
    {
        $query  = trim($item->getTitle());
        if ($query === '') {
            return null;
        }

        $prices = $this->priceChartingService->searchAndFetchPrices($userId, $query);
        if ($prices === null) {
            return null;
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $item->setMarketValue($prices['cib']);
        $item->setMarketValueLoose($prices['loose']);
        $item->setMarketValueNew($prices['new']);
        $item->setMarketValueCurrency('USD');
        $item->setMarketValueFetchedAt($now);
        $item->setUpdatedAt($now);

        return $this->mapper->update($item);
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
