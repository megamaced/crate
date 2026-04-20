<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

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
    ): array {
        $items = $this->mapper->findAll($userId, $category);

        if ($scope === 'owned') {
            $items = array_values(array_filter($items, fn($i) => $i->getStatus() === 'owned'));
        } elseif ($scope === 'wanted') {
            $items = array_values(array_filter($items, fn($i) => $i->getStatus() === 'wanted'));
        } else {
            $items = array_values($items);
        }

        $headers = $this->buildHeaders($includeEnriched, $includeMarket, $category);
        $rows    = array_map(fn($i) => $this->itemToRow($i, $includeEnriched, $includeMarket), $items);

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
    private function buildHeaders(bool $includeEnriched, bool $includeMarket, ?string $category = null): array
    {
        $artistLabel  = match ($category) {
            'film'  => 'Director',
            'book'  => 'Author',
            'game'  => 'Developer',
            'comic' => 'Writer',
            default => 'Artist',
        };
        $barcodeLabel = ($category === 'book') ? 'ISBN' : 'Barcode';
        $labelLabel   = match ($category) {
            'film'          => 'Studio',
            'book', 'game', 'comic' => 'Publisher',
            default         => 'Label',
        };

        $h = ['Category', $artistLabel, 'Title', 'Format', 'Year', 'Status', 'EnrichmentId', $barcodeLabel, $labelLabel, 'Notes'];
        if ($includeEnriched) {
            array_push($h, 'Genres', 'Country', 'PressingNotes', 'Tracklist', 'ArtistBio');
        }
        if ($includeMarket) {
            array_push($h, 'MarketValue', 'MarketCurrency', 'MarketValueFetchedAt');
        }
        return $h;
    }

    /** @return string[] */
    private function itemToRow(MediaItem $item, bool $includeEnriched, bool $includeMarket): array
    {
        $row = [
            $item->getCategory()  ?? 'music',
            $item->getArtist()    ?? '',
            $item->getTitle()     ?? '',
            $item->getFormat()    ?? '',
            $item->getYear() !== null ? (string) $item->getYear() : '',
            $item->getStatus()    ?? '',
            $item->getDiscogsId() ?? '',
            $item->getBarcode()   ?? '',
            $item->getLabel()     ?? '',
            $item->getNotes()     ?? '',
        ];

        if ($includeEnriched) {
            $row[] = $item->getGenres()        ?? '';
            $row[] = $item->getCountry()       ?? '';
            $row[] = $item->getPressingNotes() ?? '';
            $row[] = $item->getTracklist()     ?? '';
            $row[] = $item->getArtistBio()     ?? '';
        }

        if ($includeMarket) {
            $row[] = $item->getMarketValue() !== null ? (string) $item->getMarketValue() : '';
            $row[] = $item->getMarketValueCurrency()  ?? '';
            $row[] = $item->getMarketValueFetchedAt() ?? '';
        }

        return $row;
    }

    private function buildCsv(array $headers, array $rows): string
    {
        $buf = fopen('php://memory', 'r+');
        if ($buf === false) {
            throw new \RuntimeException('Failed to open memory buffer for CSV export');
        }
        // UTF-8 BOM so Excel opens it correctly
        fwrite($buf, "\xEF\xBB\xBF");
        fputcsv($buf, $headers);
        foreach ($rows as $row) {
            fputcsv($buf, array_map(fn($v) => $this->sanitizeForSpreadsheet($v), $row));
        }
        rewind($buf);
        $content = stream_get_contents($buf);
        fclose($buf);
        return $content;
    }

    /**
     * Prevent CSV/spreadsheet formula injection: cells whose first character
     * is =, +, -, @, TAB or CR can trigger formula evaluation when opened in
     * Excel / LibreOffice / Google Sheets. Prefix with a single quote so the
     * content is treated as text.
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

        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            unlink($tmp);
            throw new \RuntimeException('Failed to open temporary ZIP archive');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlContentTypes());
        $zip->addFromString('_rels/.rels', $this->xlRels());
        $zip->addFromString('xl/workbook.xml', $this->xlWorkbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlWorkbookRels());
        $zip->addFromString('xl/styles.xml', $this->xlStyles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->xlSheet($headers, $rows));

        $zip->close();

        $content = file_get_contents($tmp);
        unlink($tmp);
        if ($content === false) {
            throw new \RuntimeException('Failed to read generated XLSX file');
        }
        return $content;
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
            $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $text . '</t></is></c>';
        }
        $xml .= '</row>';

        // Data rows
        foreach ($rows as $ri => $row) {
            $rowNum = $ri + 2;
            $xml   .= '<row r="' . $rowNum . '">';
            foreach ($row as $ci => $value) {
                $ref  = $this->cellRef($ci, $rowNum);
                $text = htmlspecialchars($this->sanitizeForSpreadsheet($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
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
