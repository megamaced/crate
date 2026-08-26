<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\ExportService;
use OCA\Crate\Service\ImportService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Both CSV paths disable PHP's escape character so they speak RFC 4180, the
 * dialect every spreadsheet reads and writes. Under PHP's historical default a
 * backslash escapes the next character, so a cell ending in one — a ripped-from
 * path, a band written "AC\DC\" — leaves the closing quote looking escaped and
 * the parser runs on into the rest of the file, folding every later row into
 * that one cell.
 */
#[AllowMockObjectsWithoutExpectations]
class CsvEscapingTest extends TestCase
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

    public function testACellEndingInABackslashDoesNotSwallowTheRowsBelowIt(): void
    {
        $parsed = $this->parse(implode("\n", [
            'Artist,Title,Notes',
            'Miles Davis,Kind of Blue,"ripped from C:\Music\"',
            'Sun Ra,Lanquidity,clean rip',
        ]) . "\n");

        self::assertCount(2, $parsed['rows'], 'the row after the backslash cell has to survive');
        self::assertSame('ripped from C:\Music\\', $parsed['rows'][0][2]);
        self::assertSame('Sun Ra', $parsed['rows'][1][0]);
        self::assertSame('clean rip', $parsed['rows'][1][2]);
    }

    public function testADoubledQuoteIsStillTheOnlyWayToEscapeAQuote(): void
    {
        $parsed = $this->parse(implode("\n", [
            'Artist,Title,Notes',
            'Radiohead,"OK Computer","the ""OKNOTOK"" reissue"',
        ]) . "\n");

        self::assertSame('OK Computer', $parsed['rows'][0][1]);
        self::assertSame('the "OKNOTOK" reissue', $parsed['rows'][0][2]);
    }

    public function testAnExportedBackslashSurvivesBeingImportedBack(): void
    {
        $csv = $this->buildCsv(
            ['Artist', 'Title', 'Notes'],
            [
                ['Miles Davis', 'Kind of Blue', 'ripped from C:\Music\\'],
                ['Sun Ra', 'Lanquidity', 'clean rip'],
            ],
        );

        $parsed = $this->parse($csv);

        self::assertSame(['Artist', 'Title', 'Notes'], $parsed['headers']);
        self::assertCount(2, $parsed['rows']);
        self::assertSame('ripped from C:\Music\\', $parsed['rows'][0][2]);
        self::assertSame('clean rip', $parsed['rows'][1][2]);
    }

    /**
     * @return array{headers: string[], rows: array<int, array<int, string|null>>}
     */
    private function parse(string $csv): array
    {
        $service = new ImportService(
            $this->createMock(MediaItemMapper::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
        );

        return $service->parseFile($this->csvFile($csv), 'collection.csv');
    }

    /**
     * ExportService::buildCsv is private and the export only needs its mapper
     * for the item list, so drive the writer directly.
     *
     * @param string[]               $headers
     * @param array<int, string[]>   $rows
     */
    private function buildCsv(array $headers, array $rows): string
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        /** @var string $csv */
        $csv = $reflection->getMethod('buildCsv')->invoke($service, $headers, $rows);
        return $csv;
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
