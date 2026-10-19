<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Exception\PriceChartingRateLimitException;

/**
 * Spaces PriceCharting calls to the provider's documented limit of one per
 * second for each API token. PriceCharting blocks calls over the limit and
 * revokes access if that persists, so the spacing has to hold across
 * everything that shares a token: the search and product calls of one lookup,
 * a bulk refresh, and other requests running in other PHP workers.
 *
 * A call is admitted by claiming the token's slot in ApiSlotStore, which
 * succeeds for exactly one caller once the previous call is INTERVAL behind
 * it, and moves the next slot to INTERVAL after this admission. A caller
 * whose slot is not free sleeps until it is due and then claims again, so a
 * back-off set while it slept, or another caller that got in first, is seen
 * before it calls.
 *
 * A caller that cannot be admitted within MAX_WAIT is refused rather than
 * queued, and a refusal from PriceCharting holds the token's slot closed for
 * BACKOFF_SECONDS, so a blocked token is not driven further into excess.
 */
class PriceChartingThrottle
{
    /** One call per second, plus a margin for clock skew between servers. */
    public const INTERVAL = 1.1;

    /** Longest a request waits to be admitted before reporting rate limiting. */
    public const MAX_WAIT = 8.0;

    /** How long a refusal from PriceCharting holds the token's slot closed. */
    public const BACKOFF_SECONDS = 60.0;

    public function __construct(
        private readonly ApiSlotStore $slots,
    ) {
    }

    /**
     * Wait until $token may make its next call, and claim it.
     *
     * @throws PriceChartingRateLimitException when that would take longer than MAX_WAIT
     */
    public function acquire(string $token): void
    {
        $key = self::key($token);
        $deadline = $this->now() + self::micros(self::MAX_WAIT);

        while (true) {
            $now = $this->now();
            $freeAt = $this->slots->claim($key, $now, $now + self::micros(self::INTERVAL));
            if ($freeAt === null) {
                return;
            }
            if ($freeAt > $deadline) {
                throw new PriceChartingRateLimitException('PriceCharting rate limit: too many queued requests.');
            }
            $this->sleep(max(1, $freeAt - $now));
        }
    }

    /** Hold $token's calls back after PriceCharting has refused one. */
    public function backOff(string $token): void
    {
        $this->slots->holdUntil(self::key($token), $this->now() + self::micros(self::BACKOFF_SECONDS));
    }

    /** The token itself never reaches the database. */
    private static function key(string $token): string
    {
        return hash('sha256', 'pricecharting:' . $token);
    }

    private static function micros(float $seconds): int
    {
        return (int) round($seconds * 1_000_000);
    }

    /** Microseconds since the epoch. */
    protected function now(): int
    {
        return (int) round(microtime(true) * 1_000_000);
    }

    protected function sleep(int $micros): void
    {
        usleep($micros);
    }
}
