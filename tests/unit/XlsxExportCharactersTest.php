<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ExportService;
use PHPUnit\Framework\TestCase;

/**
 * A workbook is only useful if it parses. `htmlspecialchars(..., ENT_XML1)`
 * escapes markup but leaves the C0 control characters XML 1.0 forbids
 * outright — they have no escape at all — so free text an API client stored
 * travelled into the worksheet as raw bytes and produced a file spreadsheet
 * software reports as damaged.
 */
class XlsxExportCharactersTest extends TestCase
{
    /** @param array<int, array<int, string>> $rows */
    private function sheet(array $headers, array $rows): string
    {
        $service = (new \ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
        $method  = new \ReflectionMethod(ExportService::class, 'xlSheet');
        return (string) $method->invoke($service, $headers, $rows);
    }

    private function assertParses(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $parsed = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertNotFalse($parsed, 'worksheet did not parse: ' . implode(
            '; ',
            array_map(static fn(\LibXMLError $e): string => trim($e->message), $errors),
        ));
        return $parsed;
    }

    public function testWorksheetWithC0ControlsStillParses(): void
    {
        $xml = $this->sheet(
            ["Not\x01es"],
            [["bad\x01cell"], ["\x00\x08\x0B\x0C\x0E\x1F"]],
        );
        $this->assertParses($xml);
        self::assertStringNotContainsString("\x01", $xml);
    }

    public function testControlCharactersAreDroppedButSurroundingTextSurvives(): void
    {
        $xml    = $this->sheet(['Notes'], [["bad\x01cell"]]);
        $parsed = $this->assertParses($xml);

        $texts = [];
        foreach ($parsed->sheetData->row as $row) {
            foreach ($row->c as $cell) {
                $texts[] = (string) $cell->is->t;
            }
        }
        self::assertSame(['Notes', 'badcell'], $texts);
    }

    public function testLegalWhitespaceAndMarkupAreStillHandled(): void
    {
        // Tab and newline are legal XML characters and must survive the strip;
        // markup has to be escaped rather than dropped.
        $xml    = $this->sheet(['Notes'], [["a\tb\nc<d>&'\""]]);
        $parsed = $this->assertParses($xml);
        self::assertSame("a\tb\nc<d>&'\"", (string) $parsed->sheetData->row[1]->c[0]->is->t);
    }

    public function testCarriageReturnIsKeptInTheEmittedBytes(): void
    {
        // CR is legal, so the strip must leave it alone. It is not asserted
        // through a parse: XML 1.0 §2.11 has the *parser* normalise a literal
        // CR to LF on read, which is spec behaviour and not this code's doing.
        $xml = $this->sheet(['Notes'], [["a\rb"]]);
        self::assertStringContainsString("a\rb", $xml);
    }

    public function testAstralCharactersSurvive(): void
    {
        // Outside the BMP, and perfectly legal — an over-eager strip would eat
        // emoji and less common CJK out of users' notes.
        $xml    = $this->sheet(['Notes'], [['💿 別れ']]);
        $parsed = $this->assertParses($xml);
        self::assertSame('💿 別れ', (string) $parsed->sheetData->row[1]->c[0]->is->t);
    }

    public function testInvalidUtf8IsNotWrittenIntoTheDocument(): void
    {
        // A lone continuation byte cannot be encoded; the document has to stay
        // parseable rather than carrying it through.
        $this->assertParses($this->sheet(['Notes'], [["caf\xE9 lone"]]));
    }
}
