<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Exception\RecommendationUnavailableException;
use OCA\Crate\Service\AbstractApiService;
use OCA\Crate\Service\DiscogsService;
use OCA\Crate\Service\LocalSimilarityScorer;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\OpenLibraryService;
use OCA\Crate\Service\RawgService;
use OCA\Crate\Service\RecommendationService;
use OCA\Crate\Service\TmdbService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Online suggestions are cached for a week under a key made of the item alone,
 * so whatever is cached is what every user sees. A lookup that could not be
 * made — no token — or that failed used to come back as an empty list, the
 * same shape as "the provider has no suggestions", and was cached as one:
 * Alice without a TMDB token emptied the rail for Bob, who has one, and for
 * herself once she added hers.
 */
#[AllowMockObjectsWithoutExpectations]
class RecommendationAvailabilityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cached = [];

    /** @var list<string|\Exception> responses the HTTP client gives, in order */
    private array $responses = [];

    /** @var list<string> */
    private array $requested = [];

    public function testAUserWithoutATokenLeavesNothingCachedForOneWithIt(): void
    {
        $tmdb = $this->createMock(TmdbService::class);
        $tmdb->method('similar')->willReturnCallback(
            static fn(string $userId): array => $userId === 'alice'
                ? throw new RecommendationUnavailableException('no token')
                : [['tmdbId' => '27205', 'title' => 'Inception']],
        );
        $service = $this->service(tmdb: $tmdb);

        self::assertSame([], $service->online($this->film(), 'alice'));
        self::assertSame([], $this->cached);

        $bobs = $service->online($this->film(), 'bob');
        self::assertSame('27205', $bobs[0]['tmdbId']);
        self::assertCount(1, $this->cached, 'the provider\'s answer is cached');
        // And Alice, without a token, now sees what the provider answered.
        self::assertSame($bobs, $service->online($this->film(), 'alice'));
    }

    public function testAFailureIsNotCachedAndItsLoggedMessageIsRedacted(): void
    {
        $rawg = $this->createMock(RawgService::class);
        $rawg->method('similar')->willThrowException(new \RuntimeException(
            'Client error: `GET https://api.rawg.io/api/games/1/game-series?key=s3cr3t` resulted in a `401`',
            401,
        ));
        $logged = '';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $msg, array $context) use (&$logged): void {
            $logged = (string) $context['msg'];
        });

        $game = $this->film();
        $game->setCategory('game');
        self::assertSame([], $this->service(rawg: $rawg, logger: $logger)->online($game, 'alice'));

        self::assertSame([], $this->cached);
        self::assertStringNotContainsString('s3cr3t', $logged);
        self::assertStringContainsString('REDACTED', $logged);
    }

    public function testATruncatedAnswerIsNotCachedForTheNextUser(): void
    {
        // Alice's lookup gets a body cut short; Bob's, a moment later, a real
        // answer. The truncated one must not stand in for it.
        $this->responses = ['{"results":', '{"results":', json_encode(['results' => [
            ['id' => 1124, 'title' => 'The Prestige', 'release_date' => '2006-10-19'],
        ]])];
        $service = $this->service(tmdb: $this->tmdb('tok'));

        self::assertSame([], $service->online($this->film(), 'alice'));
        self::assertSame([], $this->cached);

        $bobs = $service->online($this->film(), 'bob');
        self::assertSame('The Prestige', $bobs[0]['title']);
        self::assertCount(3, $this->requested);
    }

    public function testTmdbTreatsAnErrorObjectAsAFailureNotAnEmptyList(): void
    {
        $error = json_encode(['status_code' => 7, 'status_message' => 'Invalid API key']);
        $this->responses = [$error, $error];

        $this->expectException(\UnexpectedValueException::class);
        $this->tmdb('tok')->similar('alice', '27205');
    }

    public function testTmdbFallsBackPastAMalformedBodyToAnAnswer(): void
    {
        $this->responses = ['<html>Bad Gateway</html>', json_encode(['results' => [
            ['id' => 1124, 'title' => 'The Prestige', 'release_date' => '2006-10-19'],
        ]])];

        self::assertCount(1, $this->tmdb('tok')->similar('alice', '27205'));
    }

    public function testRawgOpenLibraryAndDiscogsRejectABodyWithoutTheirList(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $this->responses = [json_encode(['detail' => 'Not found.'])];
        $rawg = new RawgService($this->clients(), $this->credentials('key'), $logger);
        try {
            $rawg->similar('alice', '3498', 'Action');
            self::fail('RAWG without a results list must not read as no suggestions.');
        } catch (\UnexpectedValueException) {
        }

        $this->responses = [json_encode(['error' => 'busy'])];
        $openLibrary = new OpenLibraryService($this->clients(), $this->credentials(''), $logger);
        try {
            $openLibrary->similarBySubject('Detective and mystery stories', '/works/OL1W');
            self::fail('Open Library without a docs list must not read as no suggestions.');
        } catch (\UnexpectedValueException) {
        }

        $this->responses = ['not json'];
        $discogs = new DiscogsService($this->clients(), $this->credentials('tok'), $logger);
        $this->expectException(\UnexpectedValueException::class);
        $discogs->similarByStyle('alice', 'Rock, Math Rock', '1');
    }

    public function testAnObjectWhereTheListShouldBeIsNotAnEmptyAnswer(): void
    {
        // Decoded to PHP arrays, `{}` and `[]` are the same; it must not be
        // cached as "no suggestions" for the next user.
        $this->responses = ['{"results":{}}', '{"results":{}}', json_encode(['results' => [
            ['id' => 1124, 'title' => 'The Prestige', 'release_date' => '2006-10-19'],
        ]])];
        $service = $this->service(tmdb: $this->tmdb('tok'));

        self::assertSame([], $service->online($this->film(), 'alice'));
        self::assertSame([], $this->cached);
        self::assertSame('The Prestige', $service->online($this->film(), 'bob')[0]['title']);
    }

    public function testTmdbFallsBackPastAnErrorObjectUnderResults(): void
    {
        $this->responses = ['{"results":{"message":"temporarily unavailable"}}', json_encode(['results' => [
            ['id' => 1124, 'title' => 'The Prestige', 'release_date' => '2006-10-19'],
        ]])];

        self::assertCount(1, $this->tmdb('tok')->similar('alice', '27205'));
    }

    public function testAListOfNonObjectsIsRejected(): void
    {
        $this->responses = ['{"results":[1,2]}', '{"results":["a"]}'];

        $this->expectException(\UnexpectedValueException::class);
        $this->tmdb('tok')->similar('alice', '27205');
    }

    public function testDiscogsRejectsAnObjectWhereTheListShouldBe(): void
    {
        $this->responses = ['{"results":{}}'];
        $discogs = new DiscogsService(
            $this->clients(),
            $this->credentials('tok'),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(\UnexpectedValueException::class);
        $discogs->similarByStyle('alice', 'Rock, Math Rock', '1');
    }

    public function testAGenuinelyEmptyListIsStillAnAnswer(): void
    {
        $this->responses = [json_encode(['docs' => []])];
        $openLibrary = new OpenLibraryService(
            $this->clients(),
            $this->credentials(''),
            $this->createStub(LoggerInterface::class),
        );

        self::assertSame([], $openLibrary->similarBySubject('Detective and mystery stories', '/works/OL1W'));
    }

    public function testTmdbWithoutATokenIsUnavailableNotEmpty(): void
    {
        $this->expectException(RecommendationUnavailableException::class);
        $this->tmdb('')->similar('alice', '27205');
    }

    public function testTmdbFailingWithNothingFoundThrows(): void
    {
        $this->responses = [new \RuntimeException('timeout'), json_encode(['results' => []])];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timeout');
        $this->tmdb('tok')->similar('alice', '27205');
    }

    public function testTmdbFallsBackPastAFailureToAnAnswer(): void
    {
        $this->responses = [new \RuntimeException('timeout'), json_encode(['results' => [
            ['id' => 1124, 'title' => 'The Prestige', 'release_date' => '2006-10-19'],
        ]])];

        self::assertCount(1, $this->tmdb('tok')->similar('alice', '27205'));
    }

    public function testTmdbAnsweringEmptyTwiceIsAnEmptyAnswer(): void
    {
        $this->responses = [json_encode(['results' => []]), json_encode(['results' => []])];

        self::assertSame([], $this->tmdb('tok')->similar('alice', '27205'));
    }

    public function testRawgAndDiscogsWithoutACredentialAreUnavailable(): void
    {
        $rawg = new RawgService($this->clients(), $this->credentials(''), $this->createStub(LoggerInterface::class));
        try {
            $rawg->similar('alice', '3498', 'Action');
            self::fail('RAWG without a key must report itself unavailable.');
        } catch (RecommendationUnavailableException) {
        }

        $discogs = new DiscogsService(
            $this->clients(),
            $this->credentials(''),
            $this->createStub(LoggerInterface::class),
        );
        $this->expectException(RecommendationUnavailableException::class);
        $discogs->similarByStyle('alice', 'Rock, Math Rock', '1');
    }

    public function testRawgAndOpenLibraryFailuresAreNotEmptyAnswers(): void
    {
        $this->responses = [new \RuntimeException('bad gateway', 502)];
        $rawg = new RawgService($this->clients(), $this->credentials('key'), $this->createStub(LoggerInterface::class));
        try {
            $rawg->similar('alice', '3498', 'Action');
            self::fail('A failed RAWG call must not read as no suggestions.');
        } catch (\RuntimeException) {
        }

        $this->responses = [new \RuntimeException('bad gateway', 502)];
        $openLibrary = new OpenLibraryService(
            $this->clients(),
            $this->credentials(''),
            $this->createStub(LoggerInterface::class),
        );
        $this->expectException(\RuntimeException::class);
        $openLibrary->similarBySubject('Detective and mystery stories', '/works/OL1W');
    }

    private function film(): MediaItem
    {
        $item = new MediaItem();
        $item->setId(1);
        $item->setTitle('Inception');
        $item->setArtist('Christopher Nolan');
        $item->setCategory('film');
        $item->setGenres('Science Fiction');
        $item->setDiscogsId('27205');
        return $item;
    }

    private function tmdb(string $token): TmdbService
    {
        return new TmdbService($this->clients(), $this->credentials($token), $this->createStub(LoggerInterface::class));
    }

    private function clients(): IClientService
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function (string $url): IResponse {
            $this->requested[] = $url;
            $next = array_shift($this->responses) ?? '';
            if ($next instanceof \Exception) {
                throw $next;
            }
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn($next);
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        return $clients;
    }

    private function credentials(string $value): ICredentialsManager
    {
        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->willReturn($value === '' ? null : $value);
        return $credentials;
    }

    private function service(
        ?TmdbService $tmdb = null,
        ?RawgService $rawg = null,
        ?LoggerInterface $logger = null,
    ): RecommendationService {
        $cache = new class ($this->cached) implements ICache {
            /** @param array<string, mixed> $entries */
            public function __construct(private array &$entries)
            {
            }

            public function get($key)
            {
                return $this->entries[$key] ?? null;
            }

            public function set($key, $value, $ttl = 0)
            {
                $this->entries[$key] = $value;
                return true;
            }

            public function hasKey($key)
            {
                return isset($this->entries[$key]);
            }

            public function remove($key)
            {
                unset($this->entries[$key]);
                return true;
            }

            public function clear($prefix = '')
            {
                $this->entries = [];
                return true;
            }

            public static function isAvailable(): bool
            {
                return true;
            }
        };
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($cache);

        $config = $this->createStub(IConfig::class);
        $config->method('getUserValue')->willReturn('yes');

        return new RecommendationService(
            $this->createStub(MediaService::class),
            $this->createStub(MediaItemMapper::class),
            new LocalSimilarityScorer(),
            $this->createStub(DiscogsService::class),
            $tmdb ?? $this->createStub(TmdbService::class),
            $this->createStub(OpenLibraryService::class),
            $rawg ?? $this->createStub(RawgService::class),
            $cacheFactory,
            $config,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
