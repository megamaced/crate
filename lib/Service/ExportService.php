<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;

class ExportService
{
    public function __construct(
        private readonly MediaItemMapper $mapper,
    ) {
    }

    /**
     * Generate a CSV or XLSX export of the user's collection.
     *
     * @return array{0: string, 1: string, 2: string} [content, mimeType, filename]
     */
    public function generate(
        string $userId,
        string $format,
        string $scope,
        bool $includeEnriched,
        bool $includeMarket,
        ?string $category = null,
        bool $includePrice = false,
    ): array {
        $items = $this->mapper->findAll($userId, $category);

        if ($scope === 'owned') {
            $items = array_values(array_filter($items, fn($i) => $i->getStatus() === 'owned'));
        } elseif ($scope === 'wanted') {
            $items = array_values(array_filter($items, fn($i) => $i->getStatus() === 'wanted'));
        } else {
            $items = array_values($items);
        }

        $headers = $this->buildHeaders($includeEnriched, $includeMarket, $category, $includePrice);
        $rows    = array_map(
            fn(MediaItem $i) => $this->itemToRow($i, $includeEnriched, $includeMarket, $category, $includePrice),
            $items,
        );

        $date     = date('Y-m-d');
        $filename = 'crate-export-' . ($category ?? 'all') . '-' . $date;

        if ($format === 'xlsx') {
            $content  = $this->buildXlsx($headers, $rows);
            $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            return [$content, $mimeType, $filename . '.xlsx'];
        }

        return [$this->buildCsv($headers, $rows), 'text/csv; charset=UTF-8', $filename . '.csv'];
    }

    /** @return string[] */
    private function buildHeaders(
        bool $includeEnriched,
        bool $includeMarket,
        ?string $category = null,
        bool $includePrice = false,
    ): array {
        $artistLabel  = match ($category) {
            'film'  => 'Director',
            'book'  => 'Author',
            'game'  => 'Developer',
            'comic' => 'Writer',
            default => 'Artist',
        };
        $titleLabel   = match ($category) {
            'music' => 'Album',
            'film'  => 'Film Title',
            'game'  => 'Game Title',
            'comic' => 'Series / Volume',
            default => 'Title',
        };
        $labelLabel   = match ($category) {
            'film'                  => 'Studio',
            'book', 'game', 'comic' => 'Publisher',
            default                 => 'Label',
        };
        $pressingLabel = match ($category) {
            'music' => 'PressingNotes',
            'film'  => 'Overview',
            default => 'Description',
        };

        $h = [
            'Category',
            $artistLabel,
            $titleLabel,
            'Format',
            'Year',
            'Status',
            'EnrichmentId',
        ];

        // Barcode column only for categories that actually use it.
        // Book → ISBN; Music → Barcode; Films / Games / Comics have no barcode.
        $barcodeLabel = $this->barcodeLabel($category);
        if ($barcodeLabel !== null) {
            $h[] = $barcodeLabel;
        }

        $h[] = $labelLabel;
        $h[] = 'Notes';

        if ($includeEnriched) {
            // Every category stores Genres.
            $h[] = 'Genres';
            // Country: from Discogs (music) and TMDB (film).
            if ($this->categoryExportsCountry($category)) {
                $h[] = 'Country';
            }
            // Free-text blurb stored in `pressing_notes` — labelled to match
            // the source: Pressing Notes (music), Overview (film), Description
            // (book / game / comic).
            $h[] = $pressingLabel;
            // Tracklist only applies to music.
            if ($category === 'music' || $category === null) {
                $h[] = 'Tracklist';
            }
            // Artist / author bio: music (Discogs) + book (Open Library).
            if ($this->categoryExportsBio($category)) {
                $h[] = $artistLabel . ' Bio';
            }
            // Band member list: music only (Discogs).
            if ($category === 'music' || $category === null) {
                $h[] = 'Artist Members';
            }
        }

        if ($includeMarket && $this->categoryHasMarket($category)) {
            if ($this->categoryUsesPriceCharting($category)) {
                // PriceCharting splits the three price tiers.
                $h[] = 'Loose Price';
                $h[] = 'CIB Price';
                $h[] = 'New Price';
            }
            if ($this->categoryUsesDiscogsMarket($category)) {
                $h[] = 'Market Value';
            }
            $h[] = 'Market Currency';
            $h[] = 'Market Value Fetched At';
        }

        // Original purchase price is category-agnostic — the user can record
        // what they paid for any item, regardless of whether the category has
        // a market-value source.
        if ($includePrice) {
            $h[] = 'Purchase Price';
            $h[] = 'Purchase Currency';
        }

        return $h;
    }

    /** @return list<string> */
    private function itemToRow(
        MediaItem $item,
        bool $includeEnriched,
        bool $includeMarket,
        ?string $category = null,
        bool $includePrice = false,
    ): array {
        $row = [
            $item->getCategory()  ?? 'music',
            $item->getArtist()    ?? '',
            $item->getTitle()     ?? '',
            $item->getFormat()    ?? '',
            $item->getYear() !== null ? (string) $item->getYear() : '',
            $item->getStatus()    ?? '',
            $item->getDiscogsId() ?? '',
        ];

        if ($this->barcodeLabel($category) !== null) {
            $row[] = $item->getBarcode() ?? '';
        }

        $row[] = $item->getLabel() ?? '';
        $row[] = $item->getNotes() ?? '';

        if ($includeEnriched) {
            $row[] = $item->getGenres() ?? '';
            if ($this->categoryExportsCountry($category)) {
                $row[] = $item->getCountry() ?? '';
            }
            $row[] = $item->getPressingNotes() ?? '';
            if ($category === 'music' || $category === null) {
                $row[] = $this->flattenTracklist($item->getTracklist());
            }
            if ($this->categoryExportsBio($category)) {
                $row[] = $item->getArtistBio() ?? '';
            }
            if ($category === 'music' || $category === null) {
                $row[] = $this->flattenMembers($item->getArtistMembers());
            }
        }

        if ($includeMarket && $this->categoryHasMarket($category)) {
            // Which market columns exist follows the export's category, so that
            // every row lines up with the header. Which of them carry a value
            // follows the item's own: the "all" export spans both price
            // sources, and `market_value` holds a PriceCharting CIB price for a
            // game but a Discogs asking price for a record — so writing it into
            // both columns would invent a CIB price for every album.
            $itemCategory      = $item->getCategory() ?? 'music';
            $fromPriceCharting = $this->categoryUsesPriceCharting($itemCategory);
            $fromDiscogs       = $this->categoryUsesDiscogsMarket($itemCategory);

            if ($this->categoryUsesPriceCharting($category)) {
                $row[] = $this->priceCell($fromPriceCharting, $item->getMarketValueLoose());
                $row[] = $this->priceCell($fromPriceCharting, $item->getMarketValue());
                $row[] = $this->priceCell($fromPriceCharting, $item->getMarketValueNew());
            }
            if ($this->categoryUsesDiscogsMarket($category)) {
                $row[] = $this->priceCell($fromDiscogs, $item->getMarketValue());
            }
            $row[] = $item->getMarketValueCurrency()  ?? '';
            $row[] = $item->getMarketValueFetchedAt() ?? '';
        }

        if ($includePrice) {
            $row[] = $item->getPurchasePrice() !== null ? (string) $item->getPurchasePrice() : '';
            $row[] = $item->getPurchasePriceCurrency() ?? '';
        }

        return $row;
    }

    /**
     * One market-price cell: empty unless the row's own category is priced by
     * the source that column belongs to.
     */
    private function priceCell(bool $applies, ?float $value): string
    {
        return $applies && $value !== null ? (string) $value : '';
    }

    /**
     * Flatten the stored tracklist JSON ([{position,title,duration}, …]) into
     * a human-readable single cell, e.g. "A1. Intro (1:20); A2. Verse (3:04)".
     * Falls back to an empty string on null/malformed input.
     */
    private function flattenTracklist(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        $tracks = json_decode($json, true);
        if (!is_array($tracks)) {
            return '';
        }
        $parts = [];
        foreach ($tracks as $t) {
            if (!is_array($t)) {
                continue;
            }
            $pos   = trim((string)($t['position'] ?? ''));
            $title = trim((string)($t['title'] ?? ''));
            $dur   = trim((string)($t['duration'] ?? ''));
            if ($title === '') {
                continue;
            }
            $line = $pos !== '' ? "{$pos}. {$title}" : $title;
            if ($dur !== '') {
                $line .= " ({$dur})";
            }
            $parts[] = $line;
        }
        return implode('; ', $parts);
    }

    /**
     * Flatten the stored artist-members JSON (["Name", …]) into a
     * comma-separated cell. Falls back to an empty string on null/malformed
     * input.
     */
    private function flattenMembers(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }
        $members = json_decode($json, true);
        if (!is_array($members)) {
            return '';
        }
        $names = array_filter(array_map(
            static fn($m) => is_string($m) ? trim($m) : '',
            $members,
        ), static fn(string $n) => $n !== '');
        return implode(', ', $names);
    }

    /** Barcode column label for a category, or null to suppress it. */
    private function barcodeLabel(?string $category): ?string
    {
        return match ($category) {
            'music' => 'Barcode',
            'book'  => 'ISBN',
            // Films / games / comics don't carry a barcode on our schema.
            'film', 'game', 'comic' => null,
            // "all" export: retain a generic column so book ISBNs and music
            // barcodes both fit.
            default => 'Barcode / ISBN',
        };
    }

    /**
     * Films and books have no market-value source; their rows carry no market
     * columns even when includeMarket is requested. A null category is the
     * "all" export, which spans the ones that do.
     */
    private function categoryHasMarket(?string $category): bool
    {
        return $category === null || CrateCategories::hasMarketValue($category);
    }

    /**
     * PriceCharting quotes three tiers (loose / CIB / new) in USD, so those
     * categories get a column each. Discogs-backed music stores a single
     * Market Value in the user's display currency instead.
     */
    private function categoryUsesPriceCharting(?string $category): bool
    {
        return $category === null || CrateCategories::usesPriceCharting($category);
    }

    private function categoryUsesDiscogsMarket(?string $category): bool
    {
        return $category === null || $category === 'music';
    }

    private function categoryExportsCountry(?string $category): bool
    {
        return $category === null || in_array($category, ['music', 'film'], true);
    }

    private function categoryExportsBio(?string $category): bool
    {
        return $category === null || in_array($category, ['music', 'book'], true);
    }

    private function buildCsv(array $headers, array $rows): string
    {
        $buf = fopen('php://memory', 'r+');
        if ($buf === false) {
            throw new \RuntimeException('Failed to open memory buffer for CSV export');
        }
        // UTF-8 BOM so Excel opens it correctly
        fwrite($buf, "\xEF\xBB\xBF");
        // Escaping is disabled so the output is RFC 4180: quotes are doubled and
        // a backslash carries no meaning, matching what the importer reads back
        // and what spreadsheets expect.
        fputcsv($buf, $headers, escape: '');
        foreach ($rows as $row) {
            fputcsv($buf, array_map(fn($v) => $this->sanitizeForSpreadsheet($v), $row), escape: '');
        }
        rewind($buf);
        $content = stream_get_contents($buf);
        fclose($buf);
        return $content;
    }

    /**
     * Prevent CSV formula injection: cells whose first character is =, +, -,
     * @, TAB or CR can trigger formula evaluation when the CSV is opened in
     * Excel / LibreOffice / Google Sheets. Prefix with a single quote so the
     * content is treated as text. CSV only — an XLSX inline string is never
     * evaluated, so prefixing there would corrupt the value.
     */
    private function sanitizeForSpreadsheet(mixed $value): string
    {
        $s = (string) $value;
        if ($s === '') {
            return $s;
        }
        $first = $s[0];
        if (
            $first === '='
            || $first === '+'
            || $first === '-'
            || $first === '@'
            || $first === "\t"
            || $first === "\r"
        ) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * Build a minimal, dependency-free XLSX file using ZipArchive.
     * Uses inline strings (t="inlineStr") to avoid a shared-strings table.
     */
    private function buildXlsx(array $headers, array $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'crate_export_');
        if ($tmp === false) {
            throw new \RuntimeException('Failed to create temporary file for XLSX export');
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to open temporary ZIP archive');
            }

            $zip->addFromString('[Content_Types].xml', $this->xlContentTypes());
            $zip->addFromString('_rels/.rels', $this->xlRels());
            $zip->addFromString('xl/workbook.xml', $this->xlWorkbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlWorkbookRels());
            $zip->addFromString('xl/styles.xml', $this->xlStyles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlSheet($headers, $rows));

            if ($zip->close() !== true) {
                throw new \RuntimeException('Failed to finalise XLSX archive');
            }

            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException('Failed to read generated XLSX file');
            }
            return $content;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function xlContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function xlRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function xlWorkbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Collection" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function xlWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function xlStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function xlSheet(array $headers, array $rows): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        // Header row (style index 1 = bold)
        $xml .= '<row r="1">';
        foreach ($headers as $ci => $value) {
            $ref  = $this->cellRef($ci, 1);
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>'
                . self::xmlText($value) . '</t></is></c>';
        }
        $xml .= '</row>';

        // Data rows
        foreach ($rows as $ri => $row) {
            $rowNum = $ri + 2;
            $xml   .= '<row r="' . $rowNum . '">';
            foreach ($row as $ci => $value) {
                $ref  = $this->cellRef($ci, $rowNum);
                // No formula guard here: only a <f> element is evaluated, so an
                // inline string is text by construction.
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>'
                    . self::xmlText($value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    /**
     * Render one value as worksheet character data.
     *
     * Escaping alone is not enough. `htmlspecialchars(..., ENT_XML1)` handles
     * markup, but XML 1.0 also forbids most C0 control characters outright —
     * they have no escape, not even a numeric reference. `notes` and the
     * enrichment blurbs are free text an API client writes directly, so a
     * stored U+0001 travelled into the worksheet as a raw byte and produced a
     * workbook that spreadsheet software reports as damaged ("PCDATA invalid
     * Char value 1" from any conforming parser).
     *
     * Anything outside XML 1.0's legal set is dropped, as is any byte sequence
     * that is not valid UTF-8 — which would break the document just as surely.
     */
    private static function xmlText(mixed $value): string
    {
        $text = (string) $value;
        if ($text === '') {
            return '';
        }
        // Drop invalid UTF-8 first: the /u pattern below fails outright on it,
        // and an ill-formed sequence cannot be written to the document anyway.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        // XML 1.0 §2.2: Char ::= #x9 | #xA | #xD | [#x20-#xD7FF]
        //                      | [#xE000-#xFFFD] | [#x10000-#x10FFFF]
        $stripped = preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u',
            '',
            $text,
        );
        // preg_replace returns null only if the pattern fails on the subject;
        // the check above should prevent it, but a null would otherwise become
        // an empty cell silently.
        if ($stripped !== null) {
            $text = $stripped;
        }
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Convert zero-based column index + 1-based row to a cell reference like "A1", "B3", "AA2". */
    private function cellRef(int $colIndex, int $row): string
    {
        $col = '';
        $n   = $colIndex + 1;
        while ($n > 0) {
            $n--;
            $col = chr(65 + ($n % 26)) . $col;
            $n   = intdiv($n, 26);
        }
        return $col . $row;
    }
}
