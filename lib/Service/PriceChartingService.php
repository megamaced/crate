<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class PriceChartingService
{
    private const API_BASE = 'https://www.pricecharting.com/api';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Search PriceCharting for a product by title.
     * Returns up to 10 results: [{priceChartingId, title, platform}]
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->get(self::API_BASE . '/products', [
            'q'     => $query,
            'token' => $token,
        ]);

        $products = array_slice((array)($body['products'] ?? []), 0, 10);
        return array_values(array_map(fn(array $p) => [
            'priceChartingId' => (string)($p['id'] ?? ''),
            'title'           => (string)($p['product-name'] ?? ''),
            'platform'        => (string)($p['console-name'] ?? ''),
        ], $products));
    }

    /**
     * Fetch prices for a product by PriceCharting ID.
     * Prices are stored in cents USD; we return them as dollars (float).
     *
     * @return array{loose: float|null, cib: float|null, new: float|null}|null
     */
    public function getPrices(string $userId, string $productId): ?array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            return null;
        }

        $body = $this->get(self::API_BASE . '/product/' . rawurlencode($productId), [
            'token' => $token,
        ]);

        if (empty($body)) {
            return null;
        }

        return [
            'loose' => isset($body['loose-price']) ? round((int)$body['loose-price'] / 100, 2) : null,
            'cib'   => isset($body['cib-price'])   ? round((int)$body['cib-price']   / 100, 2) : null,
            'new'   => isset($body['new-price'])    ? round((int)$body['new-price']   / 100, 2) : null,
        ];
    }

    /**
     * Search for the best matching product and return its prices.
     * Returns null if no token, no results, or API failure.
     *
     * @return array{loose: float|null, cib: float|null, new: float|null}|null
     */
    public function searchAndFetchPrices(string $userId, string $query): ?array
    {
        $results = $this->search($userId, $query);
        if (empty($results)) {
            return null;
        }

        $productId = $results[0]['priceChartingId'];
        if ($productId === '') {
            return null;
        }

        return $this->getPrices($userId, $productId);
    }

    // -------------------------------------------------------------------------

    public function getToken(string $userId): string
    {
        return (string)($this->credentialsManager->retrieve($userId, 'crate/pricecharting_token') ?? '');
    }

    public function hasToken(string $userId): bool
    {
        return $this->getToken($userId) !== '';
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        $options = [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 10,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->warning('PriceCharting API error for {url}: {msg}', [
                'url' => $url,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
            return [];
        }
    }
}
