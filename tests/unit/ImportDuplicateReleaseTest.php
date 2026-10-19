<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\ImportService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A Discogs collection lists pressings, not albums: the 1959 Columbia
 * Kind of Blue and a 2015 reissue are two releases with two ids, and the app
 * prices and enriches each by its id. Matching on artist, title and format
 * alone called the second one a duplicate and dropped it.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportDuplicateReleaseTest extends TestCase
{
    /** @var list<MediaItem> */
    private array $inserted = [];

    public function testDistinctPressingsInOneImportAreBothKept(): void
    {
        $result = $this->import([
            $this->row('101'),
            $this->row('102'),
        ]);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['duplicates']);
        self::assertSame(['101', '102'], array_map(fn(MediaItem $i) => $i->getDiscogsId(), $this->inserted));
    }

    public function testAPressingAlreadyInTheCollectionDoesNotHideAnother(): void
    {
        $result = $this->import([$this->row('102')], [$this->existing('101')]);

        self::assertSame(1, $result['created']);
        self::assertSame(0, $result['duplicates']);
    }

    public function testARepeatedReleaseIsStillADuplicate(): void
    {
        $result = $this->import([$this->row('101'), $this->row('102')], [$this->existing('101')]);

        self::assertSame(1, $result['created']);
        self::assertSame(1, $result['duplicates']);
        self::assertSame('102', $this->inserted[0]->getDiscogsId());
    }

    public function testWithoutAnIdOnEitherSideArtistTitleAndFormatDecide(): void
    {
        // An entry with no id cannot be told apart from a pressing that has
        // one, so it is the same record as far as the import can know.
        $againstUnidentified = $this->import([$this->row('101')], [$this->existing(null)]);
        self::assertSame(1, $againstUnidentified['duplicates']);

        $this->inserted = [];
        $unidentifiedRow = $this->import([$this->row(null)], [$this->existing('101')]);
        self::assertSame(1, $unidentifiedRow['duplicates']);

        $this->inserted = [];
        $twoUnidentified = $this->import([$this->row(null), $this->row(null)]);
        self::assertSame(1, $twoUnidentified['created']);
        self::assertSame(1, $twoUnidentified['duplicates']);
    }

    public function testTheSameIdInAnotherCategoryIsNotADuplicate(): void
    {
        $film = $this->existing('101');
        $film->setCategory('film');
        $film->setFormat('Blu-ray');

        $result = $this->import([$this->row('101')], [$film]);

        self::assertSame(1, $result['created']);
    }

    public function testOtherCategoriesIdsNameTheWorkNotTheEdition(): void
    {
        // One TMDB film on two formats, and two issues of one ComicVine volume:
        // the shared id is the film's or the series', so it decides nothing.
        $films = $this->import([
            ['artist' => 'Christopher Nolan', 'title' => 'Inception', 'format' => 'DVD', 'discogsId' => '27205',
                'category' => 'film'],
            ['artist' => 'Christopher Nolan', 'title' => 'Inception', 'format' => 'Blu-ray', 'discogsId' => '27205',
                'category' => 'film'],
        ]);
        self::assertSame(2, $films['created']);
        self::assertSame(0, $films['duplicates']);

        $this->inserted = [];
        $comics = $this->import([
            ['artist' => 'Todd McFarlane', 'title' => 'Spawn #1', 'format' => 'Single Issue', 'discogsId' => '4746',
                'category' => 'comic'],
            ['artist' => 'Todd McFarlane', 'title' => 'Spawn #2', 'format' => 'Single Issue', 'discogsId' => '4746',
                'category' => 'comic'],
        ]);
        self::assertSame(2, $comics['created']);
        self::assertSame(0, $comics['duplicates']);
    }

    /** @return array<string, string|null> */
    private function row(?string $releaseId): array
    {
        return ['artist' => 'Miles Davis', 'title' => 'Kind of Blue', 'format' => 'Vinyl', 'discogsId' => $releaseId];
    }

    private function existing(?string $releaseId): MediaItem
    {
        $item = new MediaItem();
        $item->setArtist('Miles Davis');
        $item->setTitle('Kind of Blue');
        $item->setFormat('Vinyl');
        $item->setCategory('music');
        $item->setDiscogsId($releaseId);
        return $item;
    }

    /**
     * @param list<array<string, string|null>> $rows
     * @param list<MediaItem> $existing
     * @return array{created: int, duplicates: int, skipped: int, errors: string[], itemIds: int[]}
     */
    private function import(array $rows, array $existing = []): array
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->willReturn($existing);
        $mapper->method('insert')->willReturnCallback(function (MediaItem $item): MediaItem {
            $item->setId(count($this->inserted) + 1);
            $this->inserted[] = $item;
            return $item;
        });

        $service = new ImportService(
            $mapper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
        );

        return $service->import($rows, 'alice');
    }
}
