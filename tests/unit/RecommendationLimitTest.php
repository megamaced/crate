<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\MediaController;
use OCA\Crate\Service\EnrichmentService;
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\RecommendationService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How many recommendations a rail holds is the client's call — a phone shows
 * fewer than a desktop grid — but it is also how much work one request can ask
 * for, so it is bounded and a caller outside those bounds is told rather than
 * quietly given something else. Omitting it has to keep behaving as it always
 * has: existing clients send nothing.
 */
#[AllowMockObjectsWithoutExpectations]
class RecommendationLimitTest extends TestCase
{
    public function testOmittedLimitAsksForTheDefaultRail(): void
    {
        $service = $this->serviceExpecting(6);

        $response = $this->controller($service)->recommendations(42);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame(['local' => [], 'online' => [], 'onlineSource' => null], $response->getData());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function acceptedLimitProvider(): array
    {
        return ['lower bound' => [1], 'custom' => [12], 'upper bound' => [24]];
    }

    #[DataProvider('acceptedLimitProvider')]
    public function testLimitInsideTheBoundsIsPassedThrough(int $limit): void
    {
        $service = $this->serviceExpecting($limit);

        $response = $this->controller($service)->recommendations(42, 'both', $limit);

        self::assertSame(Http::STATUS_OK, $response->getStatus());
    }

    /**
     * A non-integer query value reaches the controller as 0 once the framework
     * has cast it, so it is rejected by the same bounds check.
     *
     * @return array<string, array{0: int}>
     */
    public static function rejectedLimitProvider(): array
    {
        return ['zero or non-integer' => [0], 'negative' => [-1], 'above the ceiling' => [25]];
    }

    #[DataProvider('rejectedLimitProvider')]
    public function testLimitOutsideTheBoundsIsRejected(int $limit): void
    {
        $service = $this->createMock(RecommendationService::class);
        $service->expects(self::never())->method('forItem');

        $response = $this->controller($service)->recommendations(42, 'both', $limit);

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertSame(['error' => 'Invalid limit'], $response->getData());
    }

    /** A recommendation service that accepts exactly one $limit for item 42. */
    private function serviceExpecting(int $limit): RecommendationService&MockObject
    {
        $service = $this->createMock(RecommendationService::class);
        $service->expects(self::once())
            ->method('forItem')
            ->with(42, 'alice', $limit, 'both')
            ->willReturn(['local' => [], 'online' => [], 'onlineSource' => null]);
        return $service;
    }

    private function controller(RecommendationService&MockObject $recommendations): MediaController
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new MediaController(
            'crate',
            $this->createMock(IRequest::class),
            $this->createMock(MediaService::class),
            $this->createMock(EnrichmentService::class),
            $this->createMock(MarketValueService::class),
            $recommendations,
            $session,
            $this->createMock(IConfig::class),
        );
    }
}
