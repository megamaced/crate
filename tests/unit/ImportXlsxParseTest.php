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
