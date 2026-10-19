<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Exception\PriceChartingRateLimitException;
use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class PriceChartingService extends AbstractApiService
{
    private const API_BASE = 'https://www.pricecharting.com/api';

    public function __construct(
        IClientService $clientService,
        ICredentialsManager $credentialsManager,
        LoggerInterface $logger,
        private readonly PriceChartingThrottle $throttle,
    ) {
        parent::__construct($clientService, $credentialsManager, $logger);
    }

    protected function serviceName(): string
    {
        return 'PriceCharting';
    }

    protected function credentialKey(): string
    {
        return 'crate/pricecharting_token';
    }

    /**
     * Search PriceCharting for a product by title.
     * Returns up to 10 results: [{priceChartingId, title, platform}]
     *
     * @return array<int, array<string, mixed>>
     * @throws PriceChartingRateLimitException
     */
    public function search(string $userId, string $query): array
    {
        $token = $this->getCredential($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->call('/products', $token, ['q' => $query]);
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
     * @throws PriceChartingRateLimitException
     */
    public function getPrices(string $userId, string $productId): ?array
    {
        $token = $this->getCredential($userId);
        if ($token === '') {
            return null;
        }

        $body = $this->call('/product', $token, ['id' => $productId]);
        if (empty($body)) {
            return null;
        }

        return [
            'loose' => isset($body['loose-price']) ? round((int)$body['loose-price'] / 100, 2) : null,
            'cib'   => isset($body['cib-price'])   ? round((int)$body['cib-price']   / 100, 2) : null,
            'new'   => isset($body['new-price'])   ? round((int)$body['new-price']   / 100, 2) : null,
        ];
    }

    /**
     * One paced call to PriceCharting, authenticated with the `t` parameter.
     *
     * Every call waits for the token's next slot first (see
     * PriceChartingThrottle). A 429 holds the token back and is reported as
     * rate limiting rather than as no data, so a bulk refresh pauses instead
     * of carrying on into further excess. Any other failure — including a body
     * whose `status` is `error`, which is how PriceCharting reports one — is
     * no data.
     *
     * @param array<string, string> $query
     * @return array<string, mixed>
     * @throws PriceChartingRateLimitException
     */
    private function call(string $path, string $token, array $query): array
    {
        $this->throttle->acquire($token);

        $url = self::API_BASE . $path;
        try {
            $body = $this->fetchJson($url, ['t' => $token] + $query);
        } catch (\Exception $e) {
            $this->logWarning($url, $e);
            if ($e->getCode() === 429) {
                $this->throttle->backOff($token);
                throw new PriceChartingRateLimitException('PriceCharting rate limit exceeded.', 429, $e);
            }
            return [];
        }

        return ($body['status'] ?? null) === 'error' ? [] : $body;
    }

    /**
     * Search for the product matching $query on $format and return its prices.
     * Returns null if no token, no results, no platform match, or API failure.
     * The search and the price fetch are two calls, spaced like any others.
     *
     * @return array{loose: float|null, cib: float|null, new: float|null}|null
     * @throws PriceChartingRateLimitException
     */
    public function searchAndFetchPrices(string $userId, string $query, ?string $format = null): ?array
    {
        $results = $this->search($userId, $query);
        if (empty($results)) {
            return null;
        }

        $match = self::pickByPlatform($results, $format);
        if ($match === null) {
            return null;
        }

        $productId = (string) $match['priceChartingId'];
        if ($productId === '') {
            return null;
        }

        return $this->getPrices($userId, $productId);
    }

    /**
     * PriceCharting's own name for a platform, for the formats this app offers
     * under a shorter or regional name. Anything absent is compared as written.
     * A wrong entry costs nothing beyond a miss, which is reported as "no
     * match" rather than as some other platform's price.
     *
     * @var array<string, string>
     */
    private const PLATFORM_ALIASES = [
        'ps5'             => 'playstation 5',
        'ps4'             => 'playstation 4',
        'ps3'             => 'playstation 3',
        'ps2'             => 'playstation 2',
        'ps1'             => 'playstation',
        'ps vita'         => 'playstation vita',
        'n64'             => 'nintendo 64',
        'snes'            => 'super nintendo',
        'ds'              => 'nintendo ds',
        '3ds'             => 'nintendo 3ds',
        'switch'          => 'nintendo switch',
        'switch 2'        => 'nintendo switch 2',
        'xbox series x|s' => 'xbox series x',
        'mega drive'      => 'sega genesis',
        'master system'   => 'sega master system',
        'game gear'       => 'sega game gear',
        'saturn'          => 'sega saturn',
        'dreamcast'       => 'sega dreamcast',
    ];

    /**
     * Pick the result whose platform is the one the item is actually on.
     *
     * A title like "Sonic the Hedgehog" exists on a dozen platforms at wildly
     * different prices, so taking whatever PriceCharting ranks first stores a
     * price for the wrong product. Names are compared with punctuation and
     * spacing removed ("Game Boy Advance" against "GameBoy Advance"), across
     * either side of a regional pair ("Mega Drive / Genesis"), and an exact
     * match is preferred over a containing one so "DS" does not settle for
     * "Nintendo 3DS".
     *
     * With no format to compare against, the first result stands. When a format
     * is given and nothing matches, null — the caller reports "no match", which
     * is the honest answer, rather than persisting an unrelated price.
     *
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>|null
     */
    public static function pickByPlatform(array $results, ?string $format): ?array
    {
        $wanted = self::comparableNames((string) $format, true);
        if (empty($wanted)) {
            return $results[0] ?? null;
        }

        $containsMatch = null;
        foreach ($results as $result) {
            $platforms = self::comparableNames((string)($result['platform'] ?? ''), false);
            foreach ($platforms as $platform) {
                foreach ($wanted as $want) {
                    if ($platform === $want) {
                        return $result;
                    }
                    if ($containsMatch === null && strlen($want) >= 3 && str_contains($platform, $want)) {
                        $containsMatch = $result;
                    }
                }
            }
        }
        return $containsMatch;
    }

    /**
     * Reduce a platform name to its comparable forms: each side of the slash
     * that joins regional names, lowercased and stripped of everything but
     * letters and digits. With $withAliases the app's own short names also
     * contribute PriceCharting's spelling of the same platform.
     *
     * @return list<string>
     */
    private static function comparableNames(string $value, bool $withAliases): array
    {
        $names = [];
        foreach (explode('/', $value) as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if ($withAliases && isset(self::PLATFORM_ALIASES[$part])) {
                $names[] = self::squash(self::PLATFORM_ALIASES[$part]);
            }
            $squashed = self::squash($part);
            if ($squashed !== '') {
                $names[] = $squashed;
            }
        }
        return array_values(array_unique($names));
    }

    /** Lowercase alphanumerics only, so spacing and punctuation stop mattering. */
    private static function squash(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($value));
    }
}
