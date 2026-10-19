<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Exception\PriceChartingRateLimitException;
use OCA\Crate\Service\PriceChartingThrottle;
use PHPUnit\Framework\TestCase;

/**
 * PriceCharting allows one call per second per API token, blocks calls over
 * that, and revokes access if it persists. The spacing has to hold between
 * the two calls of one lookup and between workers sharing a token — measured
 * on what was actually admitted, including when a worker wakes late or the
 * token is refused while it sleeps.
 */
class PriceChartingThrottleTest extends TestCase
{
    private const SECOND = 1_000_000;
    private const INTERVAL = 1_100_000;
    private const START = 1_000 * self::SECOND;

    private \stdClass $clock;
    private FakeApiSlotStore $store;

    protected function setUp(): void
    {
        $this->clock = new \stdClass();
        $this->clock->now = self::START;
        $this->store = new FakeApiSlotStore();
    }

    public function testConsecutiveCallsAreSpacedByTheInterval(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 3; $i++) {
            $throttle->acquire('tok');
        }

        self::assertSame(
            [self::START, self::START + self::INTERVAL, self::START + 2 * self::INTERVAL],
            $this->admitted(),
        );
    }

    public function testWorkersSharingTheStoreQueueBehindOneAnother(): void
    {
        $workerA = $this->throttle();
        $workerB = $this->throttle();

        $workerA->acquire('tok');
        $workerB->acquire('tok');
        $workerA->acquire('tok');

        self::assertSame([self::INTERVAL], $workerB->sleeps);
        $this->assertSpacing($this->admitted());
    }

    public function testDifferentTokensDoNotWaitForEachOther(): void
    {
        $throttle = $this->throttle();
        $throttle->acquire('alice-token');
        $throttle->acquire('bob-token');

        self::assertSame([], $throttle->sleeps);
    }

    public function testARefusalWhileACallerSleepsStopsItBeforeItCalls(): void
    {
        $workerA = $this->throttle();
        $workerB = $this->throttle();
        $workerA->acquire('tok');

        // B waits for the next slot; meanwhile A's call comes back 429.
        $workerB->duringSleep = function () use ($workerA, $workerB): void {
            $workerB->duringSleep = null;
            $workerA->backOff('tok');
        };

        try {
            $workerB->acquire('tok');
            self::fail('A caller that slept through a refusal must not be admitted.');
        } catch (PriceChartingRateLimitException) {
        }
        self::assertSame([self::START], $this->admitted());
    }

    public function testCallersThatWakeLateAreNotAdmittedTogether(): void
    {
        $workerA = $this->throttle();
        $workerB = $this->throttle();
        $workerC = $this->throttle();
        $workerA->acquire('tok');

        // B oversleeps by a second, and C arrives just as B finally wakes.
        $workerB->duringSleep = function (int $wake) use ($workerB, $workerC): void {
            $workerB->duringSleep = null;
            $this->clock->now = $wake + self::SECOND;
            $workerC->acquire('tok');
        };
        $workerB->acquire('tok');

        $admitted = $this->admitted();
        self::assertCount(3, $admitted);
        $this->assertSpacing($admitted);
    }

    public function testAWaitBeyondTheLimitIsRefusedWithoutCalling(): void
    {
        $this->throttle()->backOff('tok');
        $throttle = $this->throttle();

        try {
            $throttle->acquire('tok');
            self::fail('A wait beyond MAX_WAIT must be refused.');
        } catch (PriceChartingRateLimitException) {
        }
        self::assertSame([], $throttle->sleeps);
        self::assertSame([], $this->admitted());

        // Once the back-off has passed, the token is admitted at once.
        $this->clock->now += (int) (PriceChartingThrottle::BACKOFF_SECONDS * self::SECOND);
        $throttle->acquire('tok');
        self::assertSame([], $throttle->sleeps);
    }

    public function testABackOffNeverShortensAHoldAlreadyInPlace(): void
    {
        $throttle = $this->throttle();
        $throttle->backOff('tok');
        $held = $this->store->nextAt[array_key_first($this->store->nextAt)];

        $this->clock->now -= 10 * self::SECOND;
        $throttle->backOff('tok');

        self::assertSame($held, $this->store->nextAt[array_key_first($this->store->nextAt)]);
    }

    public function testTheTokenNeverBecomesAKey(): void
    {
        $this->throttle()->acquire('s3cr3t-token');

        foreach (array_keys($this->store->nextAt) as $key) {
            self::assertStringNotContainsString('s3cr3t', $key);
        }
    }

    /** @return list<int> */
    private function admitted(): array
    {
        return array_merge([], ...array_values($this->store->admitted));
    }

    /** @param list<int> $admitted */
    private function assertSpacing(array $admitted): void
    {
        sort($admitted);
        for ($i = 1; $i < count($admitted); $i++) {
            self::assertGreaterThanOrEqual(self::INTERVAL, $admitted[$i] - $admitted[$i - 1]);
        }
    }

    private function throttle(): VirtualClockThrottle
    {
        return new VirtualClockThrottle($this->store, $this->clock);
    }
}
