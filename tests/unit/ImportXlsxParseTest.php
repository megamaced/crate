<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the hand-rolled XLSX reader in ImportService: cell placement when the
 * optional `r` reference is absent (LibreOffice omits it), shared-string
 * lookup, and the inflation guard that keeps a zip bomb from exhausting
 * memory_limit mid-request.
 */
class ImportXlsxParseTest extends TestCase
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

    public function testParsesCellsWithoutReferenceAttributes(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . '<row>'
            . '<c t="s"><v>0</v></c><c t="s"><v>1</v></c><c t="s"><v>2</v></c>'
            . '<c t="inlineStr"><is><t></t></is></c>'
            . '</row>'
            . '<row>'
            . '<c t="s"><v>3</v></c>'
            . '<c t="inlineStr"><is><t>Kind of Blue</t></is></c>'
            . '<c t="inlineStr"><is><t>Vinyl</t></is></c>'
            . '</row>'
            . '</sheetData></worksheet>';
        $shared = '<?xml version="1.0"?><sst xmlns="' . $ns . '" count="4">'
            . '<si><t>Artist</t></si><si><t>Title</t></si><si><t>Format</t></si>'
            . '<si><r><t>Miles </t></r><r><t>Davis</t></r></si>'
            . '</sst>';

        $parsed = $this->parse($this->makeXlsx($sheet, $shared));

        self::assertSame(['Artist', 'Title', 'Format', ''], $parsed['headers']);
        self::assertSame([['Miles Davis', 'Kind of Blue', 'Vinyl']], $parsed['rows']);
    }

    public function testSparseCellsWithReferencesKeepTheirColumn(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . '<row r="1">'
            . '<c r="A1" t="inlineStr"><is><t>Artist</t></is></c>'
            . '<c r="B1" t="inlineStr"><is><t>Title</t></is></c>'
            . '<c r="C1" t="inlineStr"><is><t>Format</t></is></c>'
            . '</row>'
            . '<row r="2">'
            . '<c r="A2" t="inlineStr"><is><t>Miles Davis</t></is></c>'
            . '<c r="C2" t="inlineStr"><is><t>Vinyl</t></is></c>'
            . '</row>'
            . '</sheetData></worksheet>';

        $parsed = $this->parse($this->makeXlsx($sheet, null));

        self::assertSame(['Artist', 'Title', 'Format'], $parsed['headers']);
        self::assertSame([['Miles Davis', null, 'Vinyl']], $parsed['rows']);
    }

    public function testRejectsImplausiblyCompressibleWorksheet(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . str_repeat('<row><c t="inlineStr"><is><t>aaaaaaaa</t></is></c></row>', 40000)
            . '</sheetData></worksheet>';
        self::assertGreaterThan(1024 * 1024, strlen($sheet));

        $this->expectException(\RuntimeException::class);
        $this->parse($this->makeXlsx($sheet, null));
    }

    /**
     * The picker used to offer .xls and .ods, and parseFile() routed both to
     * the OOXML reader. Neither can ever parse: a genuine .xls is an OLE2
     * compound file that ZipArchive cannot open, and a genuine .ods is a zip
     * holding `content.xml` rather than the `xl/` members read above. Both
     * therefore have to be refused by name, with a message that says so,
     * rather than reaching the reader and failing there.
     */
    public function testSpreadsheetFormatsThatCannotParseAreRefusedByName(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . '<row><c t="inlineStr"><is><t>Artist</t></is></c></row>'
            . '</sheetData></worksheet>';
        // A well-formed XLSX: only the claimed extension differs.
        $path    = $this->makeXlsx($sheet, null);
        $service = (new \ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();

        foreach (['collection.xls', 'collection.ods', 'collection.numbers'] as $name) {
            try {
                $service->parseFile($path, $name);
                self::fail("Expected {$name} to be refused");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Unsupported file type', $e->getMessage());
            }
        }
    }

    // ── Worksheet resolution ────────────────────────────────────────────────

    /**
     * OOXML does not require the first sheet to live at
     * `xl/worksheets/sheet1.xml`. `xl/workbook.xml` names it by relationship
     * id and `xl/_rels/workbook.xml.rels` resolves that to the part, and a
     * workbook whose only sheet sits at sheet2.xml is perfectly valid.
     */
    public function testWorksheetIsResolvedThroughTheWorkbookRelationships(): void
    {
        $path = $this->makeWorkbook('worksheets/sheet2.xml', 'xl/worksheets/sheet2.xml');

        $parsed = $this->parse($path);

        self::assertSame(['Artist', 'Title', 'Format'], $parsed['headers']);
        self::assertSame([['Miles Davis', 'Kind of Blue', 'Vinyl']], array_values($parsed['rows']));
    }

    public function testAbsoluteAndTraversingRelationshipTargetsResolve(): void
    {
        foreach (['/xl/worksheets/sheet3.xml', 'foo/../worksheets/sheet3.xml'] as $target) {
            $path   = $this->makeWorkbook($target, 'xl/worksheets/sheet3.xml');
            $parsed = $this->parse($path);
            self::assertSame(['Artist', 'Title', 'Format'], $parsed['headers'], "target {$target}");
        }
    }

    public function testHiddenSheetsAreSkippedInFavourOfTheVisibleOne(): void
    {
        $path = $this->makeWorkbook(
            'worksheets/sheet2.xml',
            'xl/worksheets/sheet2.xml',
            hiddenFirstTarget: 'worksheets/sheet9.xml',
        );

        $parsed = $this->parse($path);
        self::assertSame(['Artist', 'Title', 'Format'], $parsed['headers']);
    }

    public function testRelationshipTargetEscapingTheWorkbookIsRefused(): void
    {
        // The target is attacker-supplied; it must not be able to select an
        // arbitrary member. Resolution falls back to sheet1.xml, which this
        // archive does not have.
        $path = $this->makeWorkbook('../../../etc/passwd', 'xl/worksheets/sheet2.xml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read worksheet');
        $this->parse($path);
    }

    public function testAWorkbookWithoutRelationshipsStillReadsSheet1(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . '<row r="1"><c t="inlineStr"><is><t>Artist</t></is></c></row>'
            . '</sheetData></worksheet>';

        $parsed = $this->parse($this->makeXlsx($sheet, null));
        self::assertSame(['Artist'], $parsed['headers']);
    }

    // ── Bounds ──────────────────────────────────────────────────────────────

    /**
     * The worksheet is streamed, and the row ceiling is applied while reading.
     * Parsed into a DOM instead, a 3 MB upload of 700,000 short rows measured
     * 728 MB resident — most of it libxml's, and therefore not something
     * `memory_limit` could contain. Preview never applied the limit at all.
     */
    public function testRowCeilingIsEnforcedWhileReadingRatherThanAfterwards(): void
    {
        $ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = '<row r="1"><c t="inlineStr"><is><t>Artist</t></is></c></row>';
        for ($i = 2; $i <= 20_010; $i++) {
            $rows .= '<row r="' . $i . '"><c><v>' . $i . '</v></c></row>';
        }
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . $rows . '</sheetData></worksheet>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many rows');
        $this->parse($this->makeXlsx($sheet, null));
    }

    public function testARowAtTheCeilingIsStillAccepted(): void
    {
        $ns   = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rows = '<row r="1"><c t="inlineStr"><is><t>Artist</t></is></c></row>';
        for ($i = 2; $i <= 20_001; $i++) {
            $rows .= '<row r="' . $i . '"><c><v>' . $i . '</v></c></row>';
        }
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . $rows . '</sheetData></worksheet>';

        $parsed = $this->parse($this->makeXlsx($sheet, null));
        self::assertCount(20_000, $parsed['rows']);
    }

    /**
     * A cell's `r` reference names its column and every column before it is
     * padded with null, so one cell claiming a far-off reference would
     * otherwise allocate an array to match.
     */
    public function testAFarOffCellReferenceDoesNotAllocateTheColumnsBeforeIt(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Artist</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>Miles</t></is></c>'
            . '<c r="XFD2" t="inlineStr"><is><t>far</t></is></c></row>'
            . '</sheetData></worksheet>';

        $parsed = $this->parse($this->makeXlsx($sheet, null));
        self::assertLessThanOrEqual(256, count($parsed['rows'][0]));
        self::assertSame('Miles', $parsed['rows'][0][0]);
    }

    public function testDoctypeInAStreamedWorksheetIsRejected(): void
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet = '<?xml version="1.0"?><!DOCTYPE worksheet><worksheet xmlns="' . $ns . '">'
            . '<sheetData><row r="1"><c t="inlineStr"><is><t>Artist</t></is></c></row>'
            . '</sheetData></worksheet>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('document type declaration');
        $this->parse($this->makeXlsx($sheet, null));
    }

    /**
     * Build a workbook whose sheet relationship points at $target, with the
     * worksheet itself stored at $member.
     */
    private function makeWorkbook(string $target, string $member, ?string $hiddenFirstTarget = null): string
    {
        $ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rns   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $sheet = '<?xml version="1.0"?><worksheet xmlns="' . $ns . '"><sheetData>'
            . '<row r="1"><c t="inlineStr"><is><t>Artist</t></is></c>'
            . '<c t="inlineStr"><is><t>Title</t></is></c>'
            . '<c t="inlineStr"><is><t>Format</t></is></c></row>'
            . '<row r="2"><c t="inlineStr"><is><t>Miles Davis</t></is></c>'
            . '<c t="inlineStr"><is><t>Kind of Blue</t></is></c>'
            . '<c t="inlineStr"><is><t>Vinyl</t></is></c></row>'
            . '</sheetData></worksheet>';

        $sheets = '';
        $rels   = '';
        if ($hiddenFirstTarget !== null) {
            $sheets .= '<sheet name="Hidden" sheetId="9" state="hidden" r:id="rId9"/>';
            $rels   .= '<Relationship Id="rId9" Type="' . $rns . '/worksheet" Target="'
                . $hiddenFirstTarget . '"/>';
        }
        $sheets .= '<sheet name="Collection" sheetId="1" r:id="rId1"/>';
        $rels   .= '<Relationship Id="rId1" Type="' . $rns . '/worksheet" Target="'
            . htmlspecialchars($target, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>';

        $workbook = '<?xml version="1.0"?><workbook xmlns="' . $ns . '" xmlns:r="' . $rns . '">'
            . '<sheets>' . $sheets . '</sheets></workbook>';
        $relsXml = '<?xml version="1.0"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';

        $path = tempnam(sys_get_temp_dir(), 'crate_test_');
        self::assertNotFalse($path);
        $this->tmpFiles[] = $path;

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $relsXml);
        $zip->addFromString($member, $sheet);
        self::assertTrue($zip->close());

        return $path;
    }

    /**
     * @return array{headers: string[], rows: array<array<string|null>>}
     */
    private function parse(string $path): array
    {
        $service = (new \ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();
        /** @var array{headers: string[], rows: array<array<string|null>>} $parsed */
        $parsed = $service->parseFile($path, 'collection.xlsx');
        return $parsed;
    }

    /** Write a minimal XLSX holding the given worksheet / shared-string parts. */
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
