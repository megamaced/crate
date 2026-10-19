<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ExportService;
use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * The XLSX reader caps each cell to bound what a crafted file can make it
 * hold. The cap used to be 32 KiB, applied with substr(): a note of 15,000 CJK
 * characters — 45,000 bytes, well inside a spreadsheet cell — came back cut to
 * 32,768 bytes, ending in a split character, with nothing said. The cap is now
 * the format's own 32,767 characters, and a cell over it is refused by name.
 */
class ImportXlsxCellLimitTest extends TestCase
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

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

    public function testExportedMultibyteNotesSurviveTheRoundTrip(): void
    {
        $notes = str_repeat('界', 15000);
        $service = (new \ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
        $sheet = (string) (new \ReflectionMethod(ExportService::class, 'xlSheet'))
            ->invoke($service, ['Artist', 'Title', 'Format', 'Notes'], [['Low Roar', '0', 'CD', $notes]]);

        $parsed = $this->parse($this->makeXlsx($sheet, null));

        self::assertSame($notes, $parsed['rows'][0][3]);
    }

    public function testACellAtTheLimitIsReadWhole(): void
    {
        $full = str_repeat('界', 32767);

        $inlineCell = '<c r="A2" t="inlineStr"><is><t>' . $full . '</t></is></c>';
        $inline = $this->parse($this->makeXlsx($this->sheet($inlineCell), null));
        self::assertSame($full, $inline['rows'][0][0]);

        $shared = $this->parse($this->makeXlsx($this->sheet('<c r="A2" t="s"><v>1</v></c>'), $this->shared($full)));
        self::assertSame($full, $shared['rows'][0][0]);
    }

    public function testAnInlineCellOverTheLimitIsRefusedByReference(): void
    {
        $over = str_repeat('界', 32768);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cell D2 holds more than 32,767 characters');
        $this->parse($this->makeXlsx($this->sheet('<c r="D2" t="inlineStr"><is><t>' . $over . '</t></is></c>'), null));
    }

    public function testASharedStringOverTheLimitIsRefusedWhereItIsUsed(): void
    {
        $over = str_repeat('a', 32768);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cell B2 holds more than 32,767 characters');
        $this->parse($this->makeXlsx($this->sheet('<c r="B2" t="s"><v>1</v></c>'), $this->shared($over)));
    }

    public function testAnUnusedOversizedSharedStringIsNoObstacle(): void
    {
        $parsed = $this->parse($this->makeXlsx(
            $this->sheet('<c r="A2" t="inlineStr"><is><t>Kind of Blue</t></is></c>'),
            $this->shared(str_repeat('a', 40000)),
        ));

        self::assertSame([['Kind of Blue']], $parsed['rows']);
    }

    /** A worksheet with a one-cell header row and $cellXml as row 2. */
    private function sheet(string $cellXml): string
    {
        return '<?xml version="1.0"?><worksheet xmlns="' . self::NS . '"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
            . '<row r="2">' . $cellXml . '</row>'
            . '</sheetData></worksheet>';
    }

    /** A shared-string table of "Notes" followed by $text. */
    private function shared(string $text): string
    {
        return '<?xml version="1.0"?><sst xmlns="' . self::NS . '" count="2">'
            . '<si><t>Notes</t></si><si><t>' . $text . '</t></si></sst>';
    }

    /** @return array{headers: string[], rows: array<array<string|null>>} */
    private function parse(string $path): array
    {
        $service = (new \ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();
        /** @var array{headers: string[], rows: array<array<string|null>>} $parsed */
        $parsed = $service->parseFile($path, 'collection.xlsx');
        return $parsed;
    }

    private function makeXlsx(string $sheetXml, ?string $sharedStringsXml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crate_test_');
        self::assertNotFalse($path);
        $this->tmpFiles[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        if ($sharedStringsXml !== null) {
            $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        }
        self::assertTrue($zip->close());

        return $path;
    }
}
