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
     * Returns the first page of up to 20 results: [{priceChartingId, title, platform}].
     * The whole page is kept for the matchers below, which need the other
     * platforms, issues and variants of a title in view to pick the right one.
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
        $products = array_slice((array)($body['products'] ?? []), 0, 20);
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

        return $this->pricesFor($userId, self::pickByPlatform($results, $format));
    }

    /**
     * Search for the comic $title names and return its prices. Comics are
     * matched on series, issue, variant and year (see pickComic()); the
     * item's format is a physical format, not anything PriceCharting files a
     * comic under, so it only ever says which binding is meant.
     *
     * @return array{loose: float|null, cib: float|null, new: float|null}|null
     * @throws PriceChartingRateLimitException
     */
    public function searchAndFetchComicPrices(string $userId, string $title, ?string $format, ?int $year): ?array
    {
        $results = $this->search($userId, $title);
        if (empty($results)) {
            return null;
        }

        return $this->pricesFor($userId, self::pickComic($results, $title, $format, $year));
    }

    /**
     * @param array<string, mixed>|null $match
     * @return array{loose: float|null, cib: float|null, new: float|null}|null
     * @throws PriceChartingRateLimitException
     */
    private function pricesFor(string $userId, ?array $match): ?array
    {
        $productId = (string) ($match['priceChartingId'] ?? '');
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

    /**
     * The binding a comic format names, as PriceCharting marks it on a
     * collected edition ("Watchmen [Hardcover] (2009)"). Only formats that are
     * a binding appear: an omnibus or a graphic novel comes in either.
     *
     * @var array<string, string>
     */
    private const COMIC_FORMAT_BINDINGS = [
        'hardcover'       => 'hardcover',
        'trade paperback' => 'paperback',
    ];

    /**
     * Pick the comic $title names out of a PriceCharting search.
     *
     * PriceCharting files a comic under its series — console-name "Comic
     * Books Spawn" — and names each product "Series [Variant] #Issue (Year)",
     * e.g. "Spawn [Newsstand] #1 (1992)". The item's title is read the same
     * way, so "Spawn #1" asks for series Spawn, issue 1 and no variant.
     *
     * Identity comes first. A product must be a comic with the title's series
     * and issue; its year must be the year the title gives, or failing that
     * the item's own, when either is known; and it must carry exactly the
     * variants the title names. A title that names none accepts no special
     * variant (Newsstand, Misprint), only the binding the format names, if it
     * names one — so a Trade Paperback never takes a [Hardcover] product's
     * price. Among what remains, the edition marked with that binding is
     * preferred to an unmarked one. Anything still ambiguous yields null, the
     * same honest "no match" pickByPlatform() gives, rather than the first
     * result: a search page is truncated, so being the only product on it
     * that fits loosely says nothing about identity.
     *
     * @param list<array<string, mixed>> $results
     * @return array<string, mixed>|null
     */
    public static function pickComic(array $results, string $title, ?string $format, ?int $year): ?array
    {
        $wanted = self::comicName($title);
        if ($wanted['series'] === '') {
            return null;
        }
        $year = $wanted['year'] ?? $year;
        $binding = self::COMIC_FORMAT_BINDINGS[strtolower(trim((string) $format))] ?? null;
        $allowed = $wanted['variants'] !== [] ? null : ($binding === null ? [] : [$binding]);

        $candidates = [];
        foreach ($results as $result) {
            if (!str_starts_with(strtolower(trim((string) ($result['platform'] ?? ''))), 'comic books')) {
                continue;
            }
            $name = self::comicName((string) ($result['title'] ?? ''));
            $variantsFit = $allowed === null
                ? $name['variants'] === $wanted['variants']
                : array_diff($name['variants'], $allowed) === [];
            if (
                $name['series'] === $wanted['series']
                && $name['issue'] === $wanted['issue']
                && ($year === null || $name['year'] === $year)
                && $variantsFit
            ) {
                $candidates[] = ['result' => $result, 'variants' => $name['variants']];
            }
        }

        if ($binding !== null && $allowed !== null) {
            $marked = array_filter($candidates, fn(array $c) => $c['variants'] === [$binding]);
            if ($marked !== []) {
                $candidates = $marked;
            }
        }

        return count($candidates) === 1 ? reset($candidates)['result'] : null;
    }

    /**
     * Split a comic's name into its comparable parts: "Spawn [Newsstand] #1
     * (1992)" gives series "spawn", issue "1", variants ["newsstand"] and year
     * 1992. Series and variants are squashed like platform names, and an
     * issue loses its leading zeros. PriceCharting's [KEY] marker is not a
     * variant.
     *
     * @return array{series: string, issue: string|null, variants: list<string>, year: int|null}
     */
    private static function comicName(string $name): array
    {
        $year = null;
        if (preg_match_all('/\((\d{4})\)/', $name, $years) > 0) {
            $year = (int) end($years[1]);
            $name = (string) preg_replace('/\(\d{4}\)/', ' ', $name);
        }

        $variants = [];
        if (preg_match_all('/\[([^\]]*)\]/', $name, $brackets) > 0) {
            foreach ($brackets[1] as $bracket) {
                $variant = self::squash($bracket);
                if ($variant !== '' && $variant !== 'key') {
                    $variants[] = $variant;
                }
            }
            $name = (string) preg_replace('/\[[^\]]*\]/', ' ', $name);
        }
        sort($variants);

        $issue = null;
        if (preg_match('/#\s*([0-9]+(?:\.[0-9]+)?[a-z]?)\b/i', $name, $match) === 1) {
            $issue = strtolower(ltrim($match[1], '0')) ?: '0';
            $name = (string) preg_replace('/#\s*[0-9]+(?:\.[0-9]+)?[a-z]?\b/i', ' ', $name, 1);
        }

        return [
            'series'   => self::squash($name),
            'issue'    => $issue,
            'variants' => array_values(array_unique($variants)),
            'year'     => $year,
        ];
    }

    /** Lowercase alphanumerics only, so spacing and punctuation stop mattering. */
    private static function squash(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($value));
    }
}
