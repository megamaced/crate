<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\MediaItemMapper;

class ImportService
{
    /** Recognised physical formats across all categories (lowercase for matching) */
    private const VALID_FORMATS = [
        // Music — Vinyl
        'vinyl', '7" single', '10"', '12" single', 'picture disc',
        'flexi-disc', 'shellac', 'lathe cut',
        // Music — Tape
        'cassette', '8-track', 'reel-to-reel', 'dat', 'dcc',
        '4-track cartridge', 'microcassette',
        // Music — Disc
        'cd', 'sacd', 'cd-r', 'shm-cd', 'hdcd', 'cdv',
        'blu-ray audio', 'dvd-audio', 'laserdisc', 'minidisc',
        // Films
        'blu-ray', '4k uhd', '3d blu-ray', 'dvd', 'hd dvd', 'vhs', 'vcd', 'betamax',
        // Books
        'hardcover', 'paperback', 'mass market paperback', 'trade paperback',
        'graphic novel', 'comic', 'audiobook cd', 'audiobook cassette',
        // Games — Current Gen
        'ps5', 'xbox series x|s', 'switch 2', 'switch', 'pc',
        // Games — PlayStation
        'ps4', 'ps3', 'ps2', 'ps1', 'psp', 'ps vita',
        // Games — Xbox
        'xbox one', 'xbox 360', 'xbox',
        // Games — Nintendo
        '3ds', 'ds', 'game boy advance', 'game boy', 'wii u', 'wii',
        'gamecube', 'n64', 'snes', 'nes',
        // Games — Sega
        'mega drive', 'saturn', 'dreamcast',
        // Games — Retro
        'atari 2600', 'commodore 64', 'amiga', 'neo geo', 'tiger',
        // Comics — Single Issues
        'single issue', 'annual', 'special', 'one-shot', 'mini-series', 'limited series',
        // Comics — Collected
        'omnibus', 'compendium',
    ];

    /** Column name aliases → canonical field name */
    private const ALIASES = [
        // Artist-equivalent across categories
        'artist'          => 'artist',
        'author'          => 'artist',
        'director'        => 'artist',
        'developer'       => 'artist',
        'writer'          => 'artist',
        // Title
        'album'           => 'title',
        'title'           => 'title',
        // Format / platform
        'format'          => 'format',
        'platform'        => 'format',
        // Year
        'year'            => 'year',
        // Notes
        'notes'           => 'notes',
        'note'            => 'notes',
        // Status
        'status'          => 'status',
        // Enrichment ID (stored in discogsId regardless of source)
        'discogsid'       => 'discogsId',
        'discogs_id'      => 'discogsId',
        'discogs id'      => 'discogsId',
        'enrichmentid'    => 'discogsId',
        'enrichment_id'   => 'discogsId',
        'enrichment id'   => 'discogsId',
        // Barcode / ISBN
        'barcode'         => 'barcode',
        'isbn'            => 'barcode',
        // Label / publisher / studio
        'label'           => 'label',
        'publisher'       => 'label',
        'studio'          => 'label',
        // Category — allows per-row override from exported Category column
        'category'        => 'category',
    ];

    public function __construct(private readonly MediaItemMapper $mapper)
    {
    }

    /**
     * Parse a CSV or XLSX file and return an array of raw row arrays.
     * First row is treated as headers; returns ['headers' => [], 'rows' => []].
     *
     * @return array{headers: string[], rows: array<array<string|null>>}
     * @throws \RuntimeException on parse failure
     */
    public function parseFile(string $tmpPath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return $this->parseCsv($tmpPath);
        }

        if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            return $this->parseXlsx($tmpPath);
        }

        throw new \RuntimeException("Unsupported file type: .{$ext}");
    }

    /** @return array{headers: string[], rows: array<array<string|null>>} */
    private function parseCsv(string $path): array
    {
        // Guard against excessively large files (10 MB limit)
        $size = filesize($path);
        if ($size === false || $size > 10 * 1024 * 1024) {
            throw new \RuntimeException('File too large (max 10 MB)');
        }

        // Strip UTF-8 BOM if present
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Could not read file');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $tmp = tmpfile();
        fwrite($tmp, $content);
        rewind($tmp);

        $headers = [];
        $rows = [];
        while (($line = fgetcsv($tmp)) !== false) {
            if (empty($headers)) {
                $headers = array_map('trim', array_map('strval', $line));
            } else {
                // Skip blank lines (all-empty or single-null-element rows)
                $nonEmpty = array_filter($line, fn($v) => $v !== null && $v !== '');
                if (!empty($nonEmpty)) {
                    $rows[] = array_map(fn($v) => $v !== '' ? $v : null, $line);
                }
            }
        }
        fclose($tmp);
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Parse XLSX (and XLS/ODS if saved as XLSX) using ZipArchive + SimpleXML.
     * Handles the standard Office Open XML format.
     *
     * @return array{headers: string[], rows: array<array<string|null>>}
     */
    private function parseXlsx(string $path): array
    {
        // Guard against excessively large files (10 MB limit)
        $size = filesize($path);
        if ($size === false || $size > 10 * 1024 * 1024) {
            throw new \RuntimeException('File too large (max 10 MB)');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open spreadsheet file');
        }

        // Load shared strings (text cells are stored by index)
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = $this->parseXmlSafe($ssXml);
            if ($ss !== null) {
                foreach ($ss->si as $si) {
                    // Concatenate all <t> elements (handles rich text runs)
                    $text = '';
                    foreach ($si->xpath('.//t') as $t) {
                        $text .= (string)$t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // Load first worksheet
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('Could not read worksheet from spreadsheet');
        }

        $sheet = $this->parseXmlSafe($sheetXml);
        if ($sheet === null) {
            throw new \RuntimeException('Could not parse worksheet XML');
        }

        $headers = [];
        $rows = [];

        $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetRows = $sheet->xpath('//x:row') ?: [];

        foreach ($sheetRows as $row) {
            $rowData = [];
            $maxCol = 0;

            foreach ($row->c as $cell) {
                // Parse column index from cell reference (e.g. "C5" → col 2)
                $ref = (string)($cell['r'] ?? '');
                preg_match('/^([A-Z]+)/', $ref, $m);
                $colIdx = $m[1] ? $this->colLetterToIndex($m[1]) : $maxCol;
                $maxCol = max($maxCol, $colIdx);

                $type = (string)($cell['t'] ?? '');
                $val  = isset($cell->v) ? (string)$cell->v : null;

                if ($type === 's' && $val !== null) {
                    // Shared string
                    $val = $sharedStrings[(int)$val] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = isset($cell->is->t) ? (string)$cell->is->t : '';
                }
                // Sparse: fill gaps with null
                while (count($rowData) < $colIdx) {
                    $rowData[] = null;
                }
                $rowData[$colIdx] = $val !== '' ? $val : null;
            }

            if (empty($headers)) {
                $headers = array_map('strval', array_map('trim', $rowData));
            } else {
                $rows[] = $rowData;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Parse an XML string with hardening against XXE and DTD-based attacks.
     * Rejects DOCTYPE/ENTITY declarations up-front and disables network access
     * for the libxml parser. Returns null if the document is malformed or unsafe.
     */
    private function parseXmlSafe(string $xml): ?\SimpleXMLElement
    {
        // Reject any DOCTYPE / ENTITY / ELEMENT declaration — well-formed XLSX
        // parts (sharedStrings.xml, sheet1.xml) never contain these, so their
        // presence signals a crafted file.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY|ELEMENT)\b/i', $xml) === 1) {
            return null;
        }
        $parsed = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        return $parsed !== false ? $parsed : null;
    }

    private function colLetterToIndex(string $letters): int
    {
        $idx = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $idx = $idx * 26 + (ord($char) - ord('A') + 1);
        }
        return $idx - 1; // 0-indexed
    }

    /**
     * Auto-detect mapping from raw header names to canonical field names.
     * Returns array keyed by header index => canonical field (or null if unknown).
     *
     * @param  string[] $headers
     * @return array<int, string|null>
     */
    public function detectMapping(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $i => $header) {
            $key = strtolower(trim($header));
            $mapping[$i] = self::ALIASES[$key] ?? null;
        }
        return $mapping;
    }

    /**
     * Apply a column mapping to raw rows, returning structured row objects.
     * mapping: header-index => canonical field name (or null = ignore).
     *
     * @param  array<array<string|null>> $rows
     * @param  array<int, string|null>   $mapping
     * @return array<array<string, string|null>>
     */
    public function applyMapping(array $rows, array $mapping): array
    {
        $result = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($mapping as $colIdx => $field) {
                if ($field === null) {
                    continue;
                }
                $item[$field] = isset($row[$colIdx]) ? trim((string)$row[$colIdx]) : null;
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Validate and import rows for a user. Returns a summary.
     *
     * @param  array<array<string, string|null>> $mappedRows
     * @param  string                            $userId
     * @return array{created: int, duplicates: int, skipped: int, errors: string[], itemIds: int[]}
     */
    private const VALID_CATEGORIES = ['music', 'film', 'book', 'game', 'comic'];

    public function import(array $mappedRows, string $userId, string $batchCategory = 'music'): array
    {
        $created    = 0;
        $duplicates = 0;
        $skipped    = 0;
        $errors     = [];
        $itemIds    = [];

        // Load existing items once for duplicate detection
        $existing = $this->mapper->findAll($userId);
        $existingKeys = [];
        foreach ($existing as $item) {
            $key = $this->dupKey($item->getArtist(), $item->getTitle(), $item->getFormat());
            $existingKeys[$key] = true;
        }

        foreach ($mappedRows as $i => $row) {
            $rowNum = $i + 2; // 1-indexed + header row

            // Per-row category override (e.g. from a re-imported export with Category column)
            $rowCategoryRaw = strtolower(trim((string)($row['category'] ?? '')));
            $category       = in_array($rowCategoryRaw, self::VALID_CATEGORIES, true)
                ? $rowCategoryRaw
                : $batchCategory;

            $artist = $row['artist'] ?? '';
            $title  = $row['title']  ?? '';
            $format = $row['format'] ?? '';

            // Validate required fields
            if (empty($artist) || empty($title)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: missing Artist or Title — skipped";
                continue;
            }

            if (empty($format)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: missing Format — skipped";
                continue;
            }

            // Validate format value
            if (!in_array(strtolower($format), self::VALID_FORMATS, true)) {
                $skipped++;
                $errors[] = "Row {$rowNum}: unrecognised format \"{$format}\" - skipped";
                continue;
            }

            // Duplicate check
            $key = $this->dupKey($artist, $title, $format);
            if (isset($existingKeys[$key])) {
                $duplicates++;
                continue;
            }

            // Parse optional fields
            $year      = isset($row['year']) && $row['year'] !== '' ? (int)$row['year'] : null;
            $notes     = $row['notes']     ?? null;
            $status    = $row['status']    ?? 'owned';
            $discogsId = $row['discogsId'] ?? null;
            $barcode   = $row['barcode']   ?? null;
            $label     = $row['label']     ?? null;

            if (!in_array($status, ['owned', 'wanted'], true)) {
                $status = 'owned';
            }

            $item = new \OCA\Crate\Db\MediaItem();
            $item->setUserId($userId);
            $item->setArtist($artist);
            $item->setTitle($title);
            $item->setFormat($format);
            $item->setYear($year);
            $item->setNotes($notes ?: null);
            $item->setStatus($status);
            $item->setDiscogsId($discogsId ?: null);
            $item->setBarcode($barcode ?: null);
            $item->setLabel($label ?: null);
            $item->setCategory($category);
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $item->setCreatedAt($now);
            $item->setUpdatedAt($now);

            $saved = $this->mapper->insert($item);
            $existingKeys[$key] = true;
            $itemIds[] = $saved->getId();
            $created++;
        }

        return [
            'created'    => $created,
            'duplicates' => $duplicates,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'itemIds'    => $itemIds,
        ];
    }

    private function dupKey(string $artist, string $title, string $format): string
    {
        return strtolower(trim($artist)) . '||' . strtolower(trim($title)) . '||' . strtolower(trim($format));
    }
}
