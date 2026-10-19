<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Exception\PriceChartingRateLimitException;
use OCA\Crate\Service\PriceChartingService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PriceCharting authenticates with a `t` query parameter and serves a single
 * product from `/api/product?id=…`. A request built any other way is refused
 * upstream, and the refusal reaches the user only as "no market value" — so
 * the shape of each request is pinned here rather than left to a mock that
 * accepts anything.
 */
#[AllowMockObjectsWithoutExpectations]
class PriceChartingRequestTest extends TestCase
{
    /** @var list<array{url: string, options: array<string, mixed>, at: int}> */
    private array $requests = [];

    /** @var list<string|\Exception> */
    private array $bodies = [];

    private \stdClass $clock;
    private FakeApiSlotStore $slots;

    protected function setUp(): void
    {
        $this->clock = new \stdClass();
        $this->clock->now = 1_000_000_000;
        $this->slots = new FakeApiSlotStore();
    }

    public function testSearchSendsTheTokenAsT(): void
    {
        $this->bodies = [json_encode(['status' => 'success', 'products' => [
            ['id' => '6910', 'product-name' => 'Chrono Trigger', 'console-name' => 'Super Nintendo'],
        ]])];

        $results = $this->service()->search('alice', 'Chrono Trigger');

        self::assertCount(1, $this->requests);
        self::assertSame('https://www.pricecharting.com/api/products', $this->requests[0]['url']);
        self::assertSame(['t' => 'tok', 'q' => 'Chrono Trigger'], $this->requests[0]['options']['query']);
        self::assertSame(
            [['priceChartingId' => '6910', 'title' => 'Chrono Trigger', 'platform' => 'Super Nintendo']],
            $results,
        );
    }

    public function testPricesComeFromTheProductEndpointById(): void
    {
        $this->bodies = [json_encode([
            'status' => 'success', 'id' => '6910',
            'loose-price' => 15000, 'cib-price' => 24999, 'new-price' => 120000,
        ])];

        $prices = $this->service()->getPrices('alice', '6910');

        self::assertCount(1, $this->requests);
        self::assertSame('https://www.pricecharting.com/api/product', $this->requests[0]['url']);
        self::assertSame(['t' => 'tok', 'id' => '6910'], $this->requests[0]['options']['query']);
        self::assertSame(['loose' => 150.0, 'cib' => 249.99, 'new' => 1200.0], $prices);
    }

    public function testAnErrorBodyIsNoDataEvenWhenItParses(): void
    {
        $error = json_encode(['status' => 'error', 'error-message' => 'Invalid token']);
        $this->bodies = [$error, $error];

        self::assertSame([], $this->service()->search('alice', 'Chrono Trigger'));
        self::assertNull($this->service()->getPrices('alice', '6910'));
    }

    public function testNoTokenMeansNoRequest(): void
    {
        $service = $this->service('');

        self::assertSame([], $service->search('alice', 'Chrono Trigger'));
        self::assertNull($service->getPrices('alice', '6910'));
        self::assertSame([], $this->requests);
    }

    public function testALookupSpacesItsSearchAndPriceCalls(): void
    {
        $this->bodies = [
            json_encode(['status' => 'success', 'products' => [
                ['id' => '6910', 'product-name' => 'Chrono Trigger', 'console-name' => 'Super Nintendo'],
            ]]),
            json_encode(['status' => 'success', 'id' => '6910', 'cib-price' => 24999]),
        ];

        $prices = $this->service()->searchAndFetchPrices('alice', 'Chrono Trigger', 'SNES');

        self::assertSame(249.99, $prices['cib']);
        self::assertCount(2, $this->requests);
        self::assertGreaterThanOrEqual(1_000_000, $this->requests[1]['at'] - $this->requests[0]['at']);
    }

    public function testA429IsReportedAsRateLimitingAndHoldsTheTokenBack(): void
    {
        $this->bodies = [new \RuntimeException('Too Many Requests', 429)];
        $service = $this->service();

        try {
            $service->search('alice', 'Chrono Trigger');
            self::fail('A 429 must not read as "no results".');
        } catch (PriceChartingRateLimitException) {
        }

        // The next lookup is refused before it reaches PriceCharting.
        $this->expectException(PriceChartingRateLimitException::class);
        try {
            $service->search('alice', 'Chrono Trigger');
        } finally {
            self::assertCount(1, $this->requests);
        }
    }

    public function testOtherFailuresAreNoData(): void
    {
        $this->bodies = [new \RuntimeException('Unauthorized', 401)];

        self::assertSame([], $this->service()->search('alice', 'Chrono Trigger'));
    }

    private function service(string $token = 'tok'): PriceChartingService
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
            $this->requests[] = ['url' => $url, 'options' => $options, 'at' => $this->clock->now];
            $body = array_shift($this->bodies) ?? '';
            if ($body instanceof \Exception) {
                throw $body;
            }
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn($body);
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);

        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->willReturnCallback(
            fn(string $user, string $key) => $user === 'alice' && $key === 'crate/pricecharting_token' ? $token : null,
        );

        return new PriceChartingService(
            $clients,
            $credentials,
            $this->createMock(LoggerInterface::class),
            new VirtualClockThrottle($this->slots, $this->clock),
        );
    }
}
