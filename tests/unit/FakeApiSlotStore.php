<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ApiSlotStore;

/**
 * ApiSlotStore's semantics in memory: claim() succeeds only when the slot has
 * passed, as its conditional UPDATE does. One instance shared by several
 * throttles models workers on the same database. Every successful claim is
 * recorded, so a test can check the spacing of what was actually admitted.
 */
final class FakeApiSlotStore extends ApiSlotStore
{
    /** @var array<string, int> */
    public array $nextAt = [];

    /** @var array<string, list<int>> admission times per key */
    public array $admitted = [];

    public function __construct()
    {
    }

    public function claim(string $key, int $now, int $nextAt): ?int
    {
        $current = $this->nextAt[$key] ?? null;
        if ($current !== null && $current > $now) {
            return $current;
        }
        $this->nextAt[$key] = $nextAt;
        $this->admitted[$key][] = $now;
        return null;
    }

    public function holdUntil(string $key, int $until): void
    {
        $this->nextAt[$key] = max($this->nextAt[$key] ?? 0, $until);
    }
}
