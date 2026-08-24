<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\AbstractApiService;
use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * RAWG, ComicVine and PriceCharting can only authenticate in the query string,
 * and an HTTP client quotes the whole effective request URI in its exception
 * message. A mistyped key is the likeliest error of all, so the 401 it produces
 * must not be the thing that writes that key into nextcloud.log — which the
 * admin UI displays, log rotation copies to disk and backups keep.
 */
#[AllowMockObjectsWithoutExpectations]
class ApiCredentialRedactionTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function credentialMessages(): array
    {
        return [
            ['Client error: `GET https://api.rawg.io/api/games?key=s3cr3t&page=1` resulted in a `401`'],
            ['cURL error 28 for https://www.pricecharting.com/api/products?q=Sonic&token=s3cr3t'],
            ['`GET https://comicvine.gamespot.com/api/search/?api_key=s3cr3t&format=json` failed'],
            ['Could not resolve host: https://example.org/x?access_token=s3cr3t'],
        ];
    }

    #[DataProvider('credentialMessages')]
    public function testCredentialNeverReachesTheLogger(string $message): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->willReturnCallback(function (string $template, array $context) use (&$logged): void {
                $logged = $context;
            });

        $service = $this->service($logger);
        $service->log(
            'https://api.rawg.io/api/games?key=s3cr3t&page=1',
            new \RuntimeException($message, 401),
        );

        self::assertStringNotContainsString('s3cr3t', implode(' ', array_map('strval', $logged)));
        self::assertStringContainsString('REDACTED', (string) $logged['msg']);
        // The URL keeps its path so the entry still says which call failed.
        self::assertSame('https://api.rawg.io/api/games', $logged['url']);
        self::assertSame(401, $logged['code']);
        self::assertSame(\RuntimeException::class, $logged['class']);
        // The exception object is not attached: its trace carries the URI too.
        self::assertArrayNotHasKey('exception', $logged);
    }

    public function testMessagesWithoutCredentialsSurviveIntact(): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function (string $template, array $context) use (&$logged): void {
                $logged = $context;
            });

        $this->service($logger)->log('https://api.discogs.com/releases/1', new \RuntimeException('Connection refused'));

        self::assertSame('Connection refused', $logged['msg']);
    }

    private function service(LoggerInterface $logger): AbstractApiService
    {
        return new class (
            $this->createStub(IClientService::class),
            $this->createStub(ICredentialsManager::class),
            $logger,
        ) extends AbstractApiService {
            protected function serviceName(): string
            {
                return 'Test';
            }

            public function log(string $url, \Throwable $e): void
            {
                $this->logWarning($url, $e);
            }
        };
    }
}
