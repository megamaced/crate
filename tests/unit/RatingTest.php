<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\MediaController;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Dto\MediaItemData;
use OCA\Crate\Exception\UserFacingException;
use OCA\Crate\Service\ActivityService;
use OCA\Crate\Service\EnrichmentService;
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\RecommendationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An item's rating is whole stars from 1 to 5. On update, null leaves it
 * alone (an older client never sends one, and its edit must not wipe it)
 * while 0 clears it.
 */
#[AllowMockObjectsWithoutExpectations]
class RatingTest extends TestCase
{
    private MediaItem $item;

    /** @var MediaItem[] */
    private array $inserted = [];

    protected function setUp(): void
    {
        $this->item = new MediaItem();
        $this->item->setId(1);
        $this->item->setUserId('alice');
        $this->item->setTitle('Kind of Blue');
        $this->item->setArtist('Miles Davis');
        $this->item->setFormat('Vinyl');
        $this->item->setCategory('music');
        $this->item->setRating(4);
    }

    /** @return list<array{0: int}> */
    public static function rejectedRatings(): array
    {
        return [[-1], [6], [10]];
    }

    #[DataProvider('rejectedRatings')]
    public function testRatingOutsideZeroToFiveIsRejected(int $rating): void
    {
        $this->expectException(UserFacingException::class);
        self::data($rating);
    }

    public function testZeroToFiveAndNullAreAccepted(): void
    {
        foreach ([0, 1, 5] as $rating) {
            self::assertSame($rating, self::data($rating)->rating);
        }
        self::assertNull(self::data(null)->rating);
    }

    public function testAnUpdateWithoutARatingKeepsTheStoredOne(): void
    {
        $this->service()->update(1, 'alice', self::data(null));

        self::assertSame(4, $this->item->getRating());
    }

    public function testAnUpdateWithZeroClearsTheRating(): void
    {
        $this->service()->update(1, 'alice', self::data(0));

        self::assertNull($this->item->getRating());
    }

    public function testAnUpdateWithStarsSetsThem(): void
    {
        $this->service()->update(1, 'alice', self::data(2));

        self::assertSame(2, $this->item->getRating());
    }

    public function testCreateStoresStarsAndTreatsZeroAsUnrated(): void
    {
        $service = $this->service();

        $service->create('alice', self::data(5));
        $service->create('alice', self::data(0));
        $service->create('alice', self::data(null));

        self::assertSame([5, null, null], array_map(fn(MediaItem $i) => $i->getRating(), $this->inserted));
    }

    public function testAReadWriteShareeCanRateAndAReadOnlyOneCannot(): void
    {
        $this->service()->update(1, 'bob', self::data(1));
        self::assertSame(1, $this->item->getRating());

        try {
            $this->service()->update(1, 'carol', self::data(5));
            self::fail('A read-only sharee changed the rating.');
        } catch (DoesNotExistException) {
        }
        self::assertSame(1, $this->item->getRating());
    }

    public function testEnrichingKeepsTheRating(): void
    {
        $this->service()->applyTmdbData(1, 'alice', ['title' => 'Kind of Blue', 'tmdbId' => '42', 'genres' => 'Jazz']);

        self::assertSame(4, $this->item->getRating());
    }

    public function testStrippingEnrichmentKeepsTheRating(): void
    {
        $service = $this->service();
        $service->applyTmdbData(1, 'alice', ['title' => 'Kind Of Blue', 'tmdbId' => '42']);

        $service->stripEnrichment(1, 'alice');

        self::assertSame('Kind of Blue', $this->item->getTitle());
        self::assertSame(4, $this->item->getRating());
    }

    public function testRatingIsInTheItemJson(): void
    {
        self::assertSame(4, $this->item->jsonSerialize()['rating']);
    }

    /**
     * What a client may send for `rating`, and what the item then holds. An
     * absent rating and an explicit null alike leave the stored 4 stars.
     *
     * @return array<string, array{0: array<string, mixed>, 1: ?int}>
     */
    public static function updateBodies(): array
    {
        return [
            'omitted'           => [[], 4],
            'explicit null'     => [['rating' => null], 4],
            'zero clears'       => [['rating' => 0], null],
            'one'               => [['rating' => 1], 1],
            'five'              => [['rating' => 5], 5],
            'digits from a form' => [['rating' => '3'], 3],
            'integral float'    => [['rating' => 2.0], 2],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('updateBodies')]
    public function testAnUpdateThroughTheControllerStoresWhatWasMeant(array $body, ?int $expected): void
    {
        $response = $this->controller($body)->update(1, 'Kind of Blue', 'Miles Davis', 'Vinyl');

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame($expected, $this->item->getRating());
    }

    /**
     * Values the dispatcher's integer cast would have bent into a valid
     * rating — "abc" into 0, which clears, 1.5 into 1 — are refused instead.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformedRatings(): array
    {
        return [
            'text'          => ['abc'],
            'fraction'      => [1.5],
            'fraction text' => ['4.5'],
            'boolean'       => [true],
            'empty text'    => [''],
            'array'         => [[3]],
            'too many'      => [6],
            'negative'      => [-1],
            'negative text' => ['-1'],
        ];
    }

    #[DataProvider('malformedRatings')]
    public function testAMalformedRatingIsRefusedAndTheStoredOneKept(mixed $rating): void
    {
        $response = $this->controller(['rating' => $rating])->update(1, 'Kind of Blue', 'Miles Davis', 'Vinyl');

        self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        self::assertSame(4, $this->item->getRating());
    }

    /** @param array<string, mixed> $body */
    private function controller(array $body): MediaController
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn(string $key, mixed $default = null) => array_key_exists($key, $body) ? $body[$key] : $default,
        );
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new MediaController(
            'crate',
            $request,
            $this->service(),
            $this->createStub(EnrichmentService::class),
            $this->createStub(MarketValueService::class),
            $this->createStub(RecommendationService::class),
            $session,
            $this->createStub(IConfig::class),
            new EnglishL10N(),
        );
    }

    private static function data(?int $rating): MediaItemData
    {
        return new MediaItemData('Kind of Blue', 'Miles Davis', 'Vinyl', category: 'music', rating: $rating);
    }

    private function service(): MediaService
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        // alice owns the item and bob holds a read/write share; carol's share
        // is read-only, which findWritableForUser() answers as not found.
        $mapper->method('findWritableForUser')->willReturnCallback(
            fn(int $id, string $userId) => $userId === 'carol'
                ? throw new DoesNotExistException('read-only')
                : $this->item,
        );
        $mapper->method('update')->willReturnArgument(0);
        $mapper->method('insert')->willReturnCallback(function (MediaItem $item): MediaItem {
            $this->inserted[] = $item;
            return $item;
        });

        return new MediaService(
            $mapper,
            $this->createStub(PlaylistItemMapper::class),
            $this->createStub(CrateShareMapper::class),
            $this->createStub(PlaylistMapper::class),
            $this->createStub(IAppDataFactory::class),
            $this->createStub(IDBConnection::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(ActivityService::class),
            $this->createStub(IConfig::class),
        );
    }
}
