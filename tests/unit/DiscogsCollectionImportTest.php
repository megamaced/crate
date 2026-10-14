<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\DiscogsController;
use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Exception\DiscogsRateLimitException;
use OCA\Crate\Service\DiscogsService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class DiscogsCollectionImportTest extends TestCase
{
    public function testImportThrowsWhenNoTokenConfigured(): void
    {
        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->willReturn(null);

        $service = new DiscogsService(
            $this->createMock(IClientService::class),
            $credentials,
            $this->createMock(LoggerInterface::class),
            $this->createMock(MediaItemMapper::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(IRootFolder::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No Discogs token configured.');
        $service->importCollection('alice');
    }

    public function testImportCollectionFetchesReleasesCreatesRecordsAndSkipsDuplicates(): void
    {
        $userId = 'alice';
        $token = 'test-discogs-token';

        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->with($userId, 'crate/discogs_token')->willReturn($token);

        // Pre-existing item in local database to trigger duplicate skip
        $existingItem = new MediaItem();
        $existingItem->setUserId($userId);
        $existingItem->setArtist('Existing Artist');
        $existingItem->setTitle('Existing Album');
        $existingItem->setFormat('Vinyl');
        $existingItem->setCategory(CrateCategories::MUSIC);

        $inserted = [];
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->with($userId)->willReturn([$existingItem]);
        $mapper->method('insert')->willReturnCallback(function (MediaItem $item) use (&$inserted): MediaItem {
            $item->setId(count($inserted) + 1);
            $inserted[] = $item;
            return $item;
        });

        // Set up mock HTTP responses
        $identityResponse = $this->createMock(IResponse::class);
        $identityResponse->method('getBody')->willReturn(json_encode([
            'id' => 1234,
            'username' => 'alice_discogs',
        ]));

        $releasesResponse = $this->createMock(IResponse::class);
        $releasesResponse->method('getBody')->willReturn(json_encode([
            'pagination' => [
                'page' => 1,
                'pages' => 1,
                'per_page' => 100,
                'items' => 3,
            ],
            'releases' => [
                [
                    'id' => 1873013,
                    'basic_information' => [
                        'id' => 1873013,
                        'title' => 'The Dark Side Of The Moon',
                        'year' => 1973,
                        'thumb' => 'https://i.discogs.com/thumb.jpg',
                        'cover_image' => 'https://i.discogs.com/cover.jpg',
                        'artists' => [
                            ['id' => 45467, 'name' => 'Pink Floyd (2)'],
                        ],
                        'formats' => [
                            ['name' => 'Vinyl', 'descriptions' => ['LP', 'Album']],
                        ],
                        'labels' => [
                            ['name' => 'Harvest', 'catno' => 'SHVL 804'],
                        ],
                        'genres' => ['Rock'],
                        'styles' => ['Prog Rock', 'Psychedelic Rock'],
                    ],
                ],
                [
                    'id' => 1150016,
                    'basic_information' => [
                        'id' => 1150016,
                        'title' => 'Cross',
                        'year' => 2007,
                        'artists' => [
                            ['id' => 39228, 'name' => 'Justice (3)'],
                        ],
                        'formats' => [
                            ['name' => 'CD', 'descriptions' => ['Album']],
                        ],
                        'labels' => [
                            ['name' => 'Ed Banger Records'],
                        ],
                        'genres' => ['Electronic'],
                        'styles' => ['Electro'],
                    ],
                ],
                [
                    'id' => 9999999,
                    'basic_information' => [
                        'id' => 9999999,
                        'title' => 'Existing Album',
                        'year' => 2020,
                        'artists' => [
                            ['id' => 11111, 'name' => 'Existing Artist'],
                        ],
                        'formats' => [
                            ['name' => 'Vinyl', 'descriptions' => ['LP']],
                        ],
                    ],
                ],
            ],
        ]));

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')->willReturnCallback(function (string $url, array $options) use ($identityResponse, $releasesResponse) {
            if (str_contains($url, '/oauth/identity')) {
                return $identityResponse;
            }
            if (str_contains($url, '/collection/folders/0/releases')) {
                return $releasesResponse;
            }
            throw new \RuntimeException('Unexpected request to ' . $url);
        });

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        // Mock personal files and log writing
        $writtenContent = null;
        $mockFile = $this->createMock(File::class);
        $mockFile->method('putContent')->willReturnCallback(function (string $content) use (&$writtenContent): void {
            $writtenContent = $content;
        });

        $today = date('Ymd');
        $expectedLogFile = "discogs-import-log-{$today}.txt";

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('newFile')->willReturnCallback(function (string $name, string $content) use (&$writtenContent, $mockFile): File {
            $writtenContent = $content;
            return $mockFile;
        });

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with($userId)->willReturn($mockFolder);

        $service = new DiscogsService(
            $clientService,
            $credentials,
            $this->createMock(LoggerInterface::class),
            $mapper,
            $this->createMock(IDBConnection::class),
            $rootFolder,
        );

        $result = $service->importCollection($userId);

        self::assertSame(2, $result['imported']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(3, $result['total']);
        self::assertSame($expectedLogFile, $result['logFile']);

        self::assertCount(2, $result['importedAlbums']);
        self::assertSame('Pink Floyd', $result['importedAlbums'][0]['artist']);
        self::assertSame('The Dark Side Of The Moon', $result['importedAlbums'][0]['album']);
        self::assertSame('Vinyl', $result['importedAlbums'][0]['format']);
        self::assertSame('Justice', $result['importedAlbums'][1]['artist']);
        self::assertSame('Cross', $result['importedAlbums'][1]['album']);
        self::assertSame('CD', $result['importedAlbums'][1]['format']);

        self::assertCount(1, $result['skippedAlbums']);
        self::assertSame('Existing Artist', $result['skippedAlbums'][0]['artist']);
        self::assertSame('Existing Album', $result['skippedAlbums'][0]['album']);
        self::assertSame('Vinyl', $result['skippedAlbums'][0]['format']);

        self::assertCount(2, $inserted);

        // Release 1: Pink Floyd
        $floyd = $inserted[0];
        self::assertSame('Pink Floyd', $floyd->getArtist());
        self::assertSame('The Dark Side Of The Moon', $floyd->getTitle());
        self::assertSame('Vinyl', $floyd->getFormat());
        self::assertSame(1973, $floyd->getYear());
        self::assertSame('1873013', $floyd->getDiscogsId());
        self::assertSame('Harvest – SHVL 804', $floyd->getLabel());
        self::assertSame('https://i.discogs.com/cover.jpg', $floyd->getArtworkPath());
        self::assertSame(CrateCategories::MUSIC, $floyd->getCategory());
        self::assertSame(CrateCategories::STATUS_OWNED, $floyd->getStatus());

        // Release 2: Justice
        $justice = $inserted[1];
        self::assertSame('Justice', $justice->getArtist());
        self::assertSame('Cross', $justice->getTitle());
        self::assertSame('CD', $justice->getFormat());
        self::assertSame(2007, $justice->getYear());
        self::assertSame('1150016', $justice->getDiscogsId());
        self::assertSame(CrateCategories::MUSIC, $justice->getCategory());

        // Check log file contents
        self::assertNotNull($writtenContent);
        self::assertStringContainsString('Import results: 2 imported, 1 skipped, 3 total.', $writtenContent);
        self::assertStringContainsString('Successfully imported albums:', $writtenContent);
        self::assertStringContainsString('- Pink Floyd - The Dark Side Of The Moon (Vinyl)', $writtenContent);
        self::assertStringContainsString('- Justice - Cross (CD)', $writtenContent);
        self::assertStringContainsString('Skipped albums:', $writtenContent);
        self::assertStringContainsString('- Existing Artist - Existing Album (Vinyl)', $writtenContent);
    }

    public function testImportCollectionRateLimitThrows(): void
    {
        $userId = 'alice';
        $token = 'test-token';

        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->willReturn($token);

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')->willThrowException(new \Exception('Rate limited', 429));

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $service = new DiscogsService(
            $clientService,
            $credentials,
            $this->createMock(LoggerInterface::class),
        );

        $this->expectException(DiscogsRateLimitException::class);
        $service->importCollection($userId);
    }

    public function testDiscogsControllerImportReturnsDataResponse(): void
    {
        $discogsService = $this->createMock(DiscogsService::class);
        $discogsService->method('importCollection')->with('alice')->willReturn([
            'imported' => 5,
            'skipped' => 1,
            'total' => 6,
            'skippedAlbums' => [],
            'logFile' => 'discogs-import-log.txt',
        ]);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $controller = new DiscogsController(
            'crate',
            $this->createMock(IRequest::class),
            $discogsService,
            $userSession,
        );

        $response = $controller->import();
        self::assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        self::assertSame(5, $data['imported']);
        self::assertSame(1, $data['skipped']);
        self::assertSame('discogs-import-log.txt', $data['logFile']);
    }

    public function testImportCollectionAppendsSuffixWhenLogFileAlreadyExists(): void
    {
        $userId = 'alice';
        $token = 'test-token';

        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->with($userId, 'crate/discogs_token')->willReturn($token);

        $identityResponse = $this->createMock(IResponse::class);
        $identityResponse->method('getBody')->willReturn(json_encode([
            'id' => 1234,
            'username' => 'alice_discogs',
        ]));

        $releasesResponse = $this->createMock(IResponse::class);
        $releasesResponse->method('getBody')->willReturn(json_encode([
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 100, 'items' => 0],
            'releases' => [],
        ]));

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')->willReturnCallback(function (string $url) use ($identityResponse, $releasesResponse) {
            if (str_contains($url, '/oauth/identity')) return $identityResponse;
            if (str_contains($url, '/collection/folders/0/releases')) return $releasesResponse;
            throw new \RuntimeException('Unexpected request to ' . $url);
        });

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $today = date('Ymd');
        $base = "discogs-import-log-{$today}";

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('nodeExists')->willReturnCallback(function (string $name) use ($base): bool {
            // Both the base file and the -1 file exist already
            if ($name === "{$base}.txt" || $name === "{$base}-1.txt") {
                return true;
            }
            return false;
        });

        $createdFile = null;
        $mockFolder->method('newFile')->willReturnCallback(function (string $name) use (&$createdFile): File {
            $createdFile = $name;
            return $this->createMock(File::class);
        });

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with($userId)->willReturn($mockFolder);

        $service = new DiscogsService(
            $clientService,
            $credentials,
            $this->createMock(LoggerInterface::class),
            $this->createMock(MediaItemMapper::class),
            $this->createMock(IDBConnection::class),
            $rootFolder,
        );

        $result = $service->importCollection($userId);

        self::assertSame("{$base}-2.txt", $result['logFile']);
        self::assertSame("{$base}-2.txt", $createdFile);
    }

    public function testImportCollectionWithWantlistImportsWantedItems(): void
    {
        $userId = 'alice';
        $token = 'test-token';

        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->with($userId, 'crate/discogs_token')->willReturn($token);

        $inserted = [];
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->with($userId)->willReturn([]);
        $mapper->method('insert')->willReturnCallback(function (MediaItem $item) use (&$inserted): MediaItem {
            $item->setId(count($inserted) + 1);
            $inserted[] = $item;
            return $item;
        });

        $identityResponse = $this->createMock(IResponse::class);
        $identityResponse->method('getBody')->willReturn(json_encode([
            'id' => 1234,
            'username' => 'alice_discogs',
        ]));

        $collectionResponse = $this->createMock(IResponse::class);
        $collectionResponse->method('getBody')->willReturn(json_encode([
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 100, 'items' => 1],
            'releases' => [
                [
                    'id' => 1001,
                    'basic_information' => [
                        'id' => 1001,
                        'title' => 'The Dark Side Of The Moon',
                        'year' => 1973,
                        'artists' => [['id' => 1, 'name' => 'Pink Floyd']],
                        'formats' => [['name' => 'Vinyl']],
                    ],
                ],
            ],
        ]));

        $wantsResponse = $this->createMock(IResponse::class);
        $wantsResponse->method('getBody')->willReturn(json_encode([
            'pagination' => ['page' => 1, 'pages' => 1, 'per_page' => 100, 'items' => 1],
            'wants' => [
                [
                    'id' => 2002,
                    'notes' => 'Looking for original pressing',
                    'basic_information' => [
                        'id' => 2002,
                        'title' => 'Discovery',
                        'year' => 2001,
                        'artists' => [['id' => 2, 'name' => 'Daft Punk']],
                        'formats' => [['name' => 'Vinyl']],
                    ],
                ],
            ],
        ]));

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('get')->willReturnCallback(function (string $url) use ($identityResponse, $collectionResponse, $wantsResponse) {
            if (str_contains($url, '/oauth/identity')) return $identityResponse;
            if (str_contains($url, '/collection/folders/0/releases')) return $collectionResponse;
            if (str_contains($url, '/wants')) return $wantsResponse;
            throw new \RuntimeException('Unexpected request to ' . $url);
        });

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $writtenLog = null;
        $mockFile = $this->createMock(File::class);
        $mockFile->method('putContent')->willReturnCallback(function (string $content) use (&$writtenLog): void {
            $writtenLog = $content;
        });

        $mockFolder = $this->createMock(Folder::class);
        $mockFolder->method('nodeExists')->willReturn(false);
        $mockFolder->method('newFile')->willReturnCallback(function (string $name, string $content) use (&$writtenLog, $mockFile): File {
            $writtenLog = $content;
            return $mockFile;
        });

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with($userId)->willReturn($mockFolder);

        $service = new DiscogsService(
            $clientService,
            $credentials,
            $this->createMock(LoggerInterface::class),
            $mapper,
            $this->createMock(IDBConnection::class),
            $rootFolder,
        );

        $result = $service->importCollection($userId, true);

        self::assertSame(2, $result['imported']);
        self::assertSame(0, $result['skipped']);
        self::assertSame(2, $result['total']);

        self::assertCount(2, $inserted);
        // First item: collection release -> owned
        self::assertSame('Pink Floyd', $inserted[0]->getArtist());
        self::assertSame(CrateCategories::STATUS_OWNED, $inserted[0]->getStatus());

        // Second item: wantlist release -> wanted
        self::assertSame('Daft Punk', $inserted[1]->getArtist());
        self::assertSame('Discovery', $inserted[1]->getTitle());
        self::assertSame(CrateCategories::STATUS_WANTED, $inserted[1]->getStatus());
        self::assertSame('Looking for original pressing', $inserted[1]->getNotes());

        // Log file checks
        self::assertNotNull($writtenLog);
        self::assertStringContainsString('- Pink Floyd - The Dark Side Of The Moon (Vinyl)', $writtenLog);
        self::assertStringContainsString('- Daft Punk - Discovery (Vinyl) [Wanted]', $writtenLog);
    }
}

