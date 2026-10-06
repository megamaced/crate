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
 * A Discogs collection CSV export imports as it is: its headers auto-map, its
 * abbreviated format strings resolve to canonical formats, and its quirks (an
 * unknown year written as 0, " (N)" artist suffixes) don't reach the stored
 * items. Video and digital releases are skipped.
 */
#[AllowMockObjectsWithoutExpectations]
class DiscogsExportImportTest extends TestCase
{
    private const HEADER = 'Catalog#,Artist,Title,Label,Format,Rating,Released,release_id,CollectionFolder,'
        . 'Date Added,Collection Media Condition,Collection Sleeve Condition,Collection Notes';

    /** @var MediaItem[] */
    private array $inserted = [];

    private ?string $tmpFile = null;

    protected function tearDown(): void
    {
        if ($this->tmpFile !== null && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    public function testHeadersMapToCrateFields(): void
    {
        $mapping = ImportService::detectMapping(str_getcsv(self::HEADER, ',', '"', ''));

        self::assertSame(
            [
                'Artist'           => 'artist',
                'Title'            => 'title',
                'Label'            => 'label',
                'Format'           => 'format',
                'Released'         => 'year',
                'release_id'       => 'discogsId',
                'Collection Notes' => 'notes',
            ],
            array_filter(array_combine(str_getcsv(self::HEADER, ',', '"', ''), $mapping)),
        );
    }

    public function testExportRowsImport(): void
    {
        $result = $this->import([
            '"SHVL 804","Pink Floyd","The Dark Side Of The Moon","Harvest","LP, Album, Gat","","1973","1873013",'
                . '"Uncategorized","2019-01-01 12:00:00","Very Good Plus (VG+)","Very Good (VG)","First UK pressing"',
            '"BEC5772","Justice (3)","Cross","Ed Banger Records","2xCD, Album","","0","1150016",'
                . '"Uncategorized","2020-02-02 10:00:00","","",""',
            '"0","The Beatles","The Beatles In Mono","Apple Records","Box, Comp, Mono, Ltd, RE, RM, 180 + 14xLP, '
                . 'Album, Mono","","2014","5953463","Uncategorized","2021-03-03 09:00:00","","",""',
            '"none","Eat (2), Die Warzau","Split","Fiction","7""","","1989","15169049","Uncategorized",'
                . '"2021-03-03 09:00:00","","",""',
            '"none","Ween","Live In Chicago","Sanctuary","DVD, NTSC + CD","","2004","2","Uncategorized",'
                . '"2021-03-03 09:00:00","","",""',
            '"none","Ween","Live In Chicago","Sanctuary","DVD, NTSC","","2004","3","Uncategorized",'
                . '"2021-03-03 09:00:00","","",""',
            '"none","Burial","Untrue","Hyperdub","File, FLAC, Album","","2007","4","Uncategorized",'
                . '"2021-03-03 09:00:00","","",""',
        ]);

        self::assertSame(5, $result['created']);
        self::assertSame(
            [
                'Row 7: unrecognised format "DVD, NTSC" - skipped',
                'Row 8: unrecognised format "File, FLAC, Album" - skipped',
            ],
            $result['errors'],
        );

        [$floyd, $justice, $beatles, $split, $ween] = $this->inserted;

        self::assertSame('Vinyl', $floyd->getFormat());
        self::assertSame(1973, $floyd->getYear());
        self::assertSame('1873013', $floyd->getDiscogsId());
        self::assertSame('Harvest', $floyd->getLabel());
        self::assertSame('First UK pressing', $floyd->getNotes());

        self::assertSame('Justice', $justice->getArtist());
        self::assertSame('CD', $justice->getFormat());
        self::assertNull($justice->getYear());

        // Longer than the 50-character format column, but it's the canonical
        // value that gets stored.
        self::assertSame('Vinyl', $beatles->getFormat());

        // Every artist of a multi-artist row loses its suffix.
        self::assertSame('Eat, Die Warzau', $split->getArtist());
        self::assertSame('7" Single', $split->getFormat());

        // The CD in a DVD + CD set is the music carrier.
        self::assertSame('CD', $ween->getFormat());
    }

    /**
     * Import CSV rows under the Discogs header the way /import/commit does.
     *
     * @param string[] $rows
     * @return array{created: int, duplicates: int, skipped: int, errors: string[], itemIds: int[]}
     */
    private function import(array $rows): array
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->willReturn([]);
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

        $path = tempnam(sys_get_temp_dir(), 'crate_test_');
        self::assertNotFalse($path);
        $this->tmpFile = $path;
        file_put_contents($path, self::HEADER . "\n" . implode("\n", $rows) . "\n");

        $parsed  = $service->parseFile($path, 'collection.csv');
        $mapping = ImportService::detectMapping($parsed['headers']);

        return $service->import(ImportService::applyMapping($parsed['rows'], $mapping), 'alice');
    }
}
