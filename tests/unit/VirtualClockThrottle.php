<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ApiSlotStore;
use OCA\Crate\Service\PriceChartingThrottle;

/**
 * A PriceChartingThrottle on a clock the test owns: sleeping advances the
 * clock instead of the test's wall time. Throttles that share one $clock and
 * one store model concurrent workers, and $duringSleep lets a test run what
 * the other workers do while this one sleeps.
 */
final class VirtualClockThrottle extends PriceChartingThrottle
{
    /** @var list<int> how long each sleep was, in microseconds */
    public array $sleeps = [];

    /** @var (\Closure(int): void)|null called with the wake time before the clock reaches it */
    public ?\Closure $duringSleep = null;

    /** @param \stdClass $clock holds the current time in microseconds in `$clock->now` */
    public function __construct(ApiSlotStore $slots, public readonly \stdClass $clock)
    {
        parent::__construct($slots);
    }

    protected function now(): int
    {
        return $this->clock->now;
    }

    protected function sleep(int $micros): void
    {
        $this->sleeps[] = $micros;
        $wake = $this->clock->now + $micros;
        if ($this->duringSleep !== null) {
            ($this->duringSleep)($wake);
        }
        $this->clock->now = max($this->clock->now, $wake);
    }
}
