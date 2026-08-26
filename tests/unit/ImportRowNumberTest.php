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
 * An import error is only actionable if the row it names is the row the user
 * can find in their spreadsheet. Blank lines are dropped during parsing, so
 * the surviving rows have to keep their original position — counting them off
 * as they arrive reports every row after the first blank line a line early.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportRowNumberTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    public function testErrorsNameTheFileLineDespiteBlankLinesAbove(): void
    {
        // Header on line 1, blanks on lines 3 and 5, so the two bad rows are
        // the user's lines 4 and 6.
        $result = $this->import(
            "Artist,Title,Format\n"
            . "Miles Davis,Kind of Blue,Vinyl\n"
            . "\n"
            . "Herbie Hancock,Head Hunters,\n"
            . ",,\n"
            . "Sun Ra,Lanquidity,Betamaxx\n",
        );

        self::assertSame(1, $result['created']);
        self::assertSame(2, $result['skipped']);
        self::assertSame(
            [
                'Row 4: missing Format — skipped',
                'Row 6: unrecognised format "Betamaxx" - skipped',
            ],
            $result['errors'],
        );
    }

    public function testRowNumbersAreUnaffectedWhenNothingIsBlank(): void
    {
        $result = $this->import(
            "Artist,Title,Format\n"
            . "Herbie Hancock,Head Hunters,\n"
            . "Sun Ra,Lanquidity,Betamaxx\n",
        );

        self::assertSame(
            [
                'Row 2: missing Format — skipped',
                'Row 3: unrecognised format "Betamaxx" - skipped',
            ],
            $result['errors'],
        );
    }

    /**
     * Parse a CSV and import it the way /import/commit does.
     *
     * @return array{created: int, duplicates: int, skipped: int, errors: string[], itemIds: int[]}
     */
    private function import(string $csv): array
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->willReturn([]);
        $mapper->method('insert')->willReturnCallback(static function (MediaItem $item): MediaItem {
            $item->setId(1);
            return $item;
        });

        $service = new ImportService(
            $mapper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
        );

        $parsed  = $service->parseFile($this->csvFile($csv), 'collection.csv');
        $mapping = ImportService::detectMapping($parsed['headers']);

        return $service->import(ImportService::applyMapping($parsed['rows'], $mapping), 'alice');
    }

    private function csvFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crate_test_');
        self::assertNotFalse($path);
        $this->tmpFiles[] = $path;
        file_put_contents($path, $content);
        return $path;
    }
}
