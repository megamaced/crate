<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\MarketValueService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

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
        // Games — Sony
        'ps5', 'ps4', 'ps3', 'ps2', 'ps1', 'ps vita', 'psp',
        // Games — Microsoft
        'xbox series x|s', 'xbox one', 'xbox 360', 'xbox',
        // Games — Nintendo
        'switch 2', 'switch', 'wii u', 'wii', 'gamecube', 'n64', 'snes', 'nes',
        '3ds', 'ds', 'game boy advance', 'game boy color', 'game boy', 'virtual boy',
        // Games — Sega
        'dreamcast', 'saturn', 'mega drive / genesis', 'master system',
        'game gear', 'sega cd', 'sega 32x',
        // Games — Atari
        'atari 2600', 'atari 5200', 'atari 7800', 'atari lynx', 'jaguar',
        // Games — SNK
        'neo geo mvs', 'neo geo aes', 'neo geo cd', 'neo geo pocket color',
        // Comics — Single Issues
        'single issue', 'annual', 'special', 'one-shot', 'mini-series', 'limited series',
        // Comics — Collected
        'omnibus', 'compendium',
    ];

    /**
     * Column length caps that mirror the DB schema; rows exceeding any of
     * these are skipped rather than being silently truncated by the DB.
     */
    // Must match the DB column widths in Version0001Date20260421000000 — the
    // up-front length check below relies on these being accurate so an
    // over-length cell is skipped with a clear error instead of overflowing
    // the column at insert time.
    private const MAX_LEN = [
        'artist'    => 500,
        'title'     => 500,
        'format'    => 50,
        'notes'     => 2000,
        'barcode'   => 50,
        'label'     => 500,
        'discogsId' => 50,
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
        'barcode / isbn'  => 'barcode', // header used by the all-categories export
        'barcode/isbn'    => 'barcode',
        // Label / publisher / studio
        'label'           => 'label',
        'publisher'       => 'label',
        'studio'          => 'label',
        // Category — allows per-row override from exported Category column
        'category'        => 'category',
        // Original purchase price + currency
        'purchase price'  => 'purchasePrice',
        'purchaseprice'   => 'purchasePrice',
        'purchase_price'  => 'purchasePrice',
        'price paid'      => 'purchasePrice',
        'pricepaid'       => 'purchasePrice',
        'price_paid'      => 'purchasePrice',
        'bought for'      => 'purchasePrice',
        'cost'            => 'purchasePrice',
        'paid'            => 'purchasePrice',
        'original price'  => 'purchasePrice',
        'purchase currency'         => 'purchasePriceCurrency',
        'purchasecurrency'          => 'purchasePriceCurrency',
        'purchase_currency'         => 'purchasePriceCurrency',
        'purchase price currency'   => 'purchasePriceCurrency',
        'purchasepricecurrency'     => 'purchasePriceCurrency',
        'purchase_price_currency'   => 'purchasePriceCurrency',
        'price currency'            => 'purchasePriceCurrency',
        'paid currency'             => 'purchasePriceCurrency',
    ];

    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly LoggerInterface $logger,
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * Parse a spreadsheet cell holding a purchase price. Strips currency
     * symbols and thousand separators, enforces the same 0..1_000_000 range
     * as MediaController::normalisePurchasePrice.
     *
     * Returns ['price' => ?float] on success (null = blank cell, treated as
     * "no purchase price recorded"), or ['error' => string] for an
     * unparseable or out-of-range value.
     *
     * Public + static so the unit test suite can exercise it without
     * standing up the full import pipeline.
     *
     * @return array{price?: ?float, error?: string}
     */
    public static function parsePurchasePriceCell(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['price' => null];
        }
        $normalised = self::normaliseDecimalString($raw);
        if ($normalised === null || !is_numeric($normalised)) {
            return ['error' => "unparseable purchase price \"{$raw}\""];
        }
        $val = (float) $normalised;
        if ($val < 0 || $val > 1_000_000) {
            return ['error' => 'purchase price out of range'];
        }
        return ['price' => $val];
    }

    /**
     * Normalise a spreadsheet money cell to a dot-decimal numeric string,
     * coping with both "1,234.56" (comma thousands) and "1.234,56" / "24,99"
     * (comma decimal) conventions. Currency symbols and spaces are stripped.
     * Returns null when nothing numeric remains.
     *
     * The previous implementation stripped every comma unconditionally, so a
     * European-formatted "24,99" became 2499 (a silent 100x error).
     */
    private static function normaliseDecimalString(string $raw): ?string
    {
        $s = preg_replace('/[^0-9.,\-]/', '', $raw);
        if ($s === '' || $s === '-') {
            return null;
        }
        $sign = str_starts_with($s, '-') ? '-' : '';
        $s = str_replace('-', '', $s);

        $hasDot   = str_contains($s, '.');
        $hasComma = str_contains($s, ',');

        if ($hasDot && $hasComma) {
            // The right-most separator is the decimal point; the other groups
            // thousands.
            $decimal   = strrpos($s, '.') > strrpos($s, ',') ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $s = str_replace($thousands, '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($hasComma) {
            // Comma only: treat as a decimal separator when it groups 1-2
            // trailing digits ("24,99"), otherwise as thousands ("1,234").
            $parts = explode(',', $s);
            if (count($parts) === 2 && strlen($parts[1]) >= 1 && strlen($parts[1]) <= 2) {
                $s = $parts[0] . '.' . $parts[1];
            } else {
                $s = str_replace(',', '', $s);
            }
        }
        // Dot-only strings are already dot-decimal; an ambiguous multi-dot
        // string (e.g. "1.234.567") fails is_numeric and is reported rather
        // than silently mis-parsed.

        return $sign . $s;
    }

    /**
     * Validate a purchase-currency cell against the shared
     * MarketValueService::SUPPORTED_CURRENCIES allowlist. Mirror of the
     * controller path, kept here so the import pipeline doesn't reach
     * into MediaController for one helper. Returns ['currency' => string]
     * on success or ['error' => string] on a missing/unsupported code.
     *
     * @return array{currency?: string, error?: string}
     */
    public static function parsePurchaseCurrencyCell(?string $raw): array
    {
        $code = strtoupper(trim((string) ($raw ?? '')));
        if ($code === '') {
            return ['error' => 'purchase price requires a currency'];
        }
        if (!in_array($code, MarketValueService::SUPPORTED_CURRENCIES, true)) {
            return ['error' => "unsupported purchase currency \"{$code}\""];
        }
        return ['currency' => $code];
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
    public static function detectMapping(array $headers): array
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
    public static function applyMapping(array $rows, array $mapping): array
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
    public function import(array $mappedRows, string $userId, string $batchCategory = CrateCategories::MUSIC): array
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
            $key = $this->dupKey(
                $item->getArtist(),
                $item->getTitle(),
                $item->getFormat(),
                (string)$item->getCategory(),
            );
            $existingKeys[$key] = true;
        }

        // Wrap the insert loop in a transaction. Big imports go from N
        // round-trips to one, and partial-failure rollback is automatic.
        $this->db->beginTransaction();

        foreach ($mappedRows as $i => $row) {
            $rowNum = $i + 2; // 1-indexed + header row

            // Per-row category override (e.g. from a re-imported export with Category column)
            $rowCategoryRaw = strtolower(trim((string)($row['category'] ?? '')));
            $category       = CrateCategories::isCategory($rowCategoryRaw) ? $rowCategoryRaw : $batchCategory;

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

            // Length validation — the DB truncates silently, so reject up-front
            // to make the user aware of the data loss.
            $overLen = null;
            foreach (self::MAX_LEN as $field => $max) {
                $value = (string)($row[$field] ?? '');
                if (strlen($value) > $max) {
                    $overLen = "{$field} exceeds {$max} chars";
                    break;
                }
            }
            if ($overLen !== null) {
                $skipped++;
                $errors[] = "Row {$rowNum}: {$overLen} — skipped";
                continue;
            }

            // Duplicate check (scoped by category — the same release can
            // legitimately exist in more than one category)
            $key = $this->dupKey($artist, $title, $format, $category);
            if (isset($existingKeys[$key])) {
                $duplicates++;
                continue;
            }

            // Parse optional fields
            $year      = isset($row['year']) && $row['year'] !== '' ? (int)$row['year'] : null;
            $notes     = $row['notes']     ?? null;
            $status    = strtolower(trim((string)($row['status'] ?? 'owned')));
            $discogsId = $row['discogsId'] ?? null;
            $barcode   = $row['barcode']   ?? null;
            $label     = $row['label']     ?? null;

            if (!CrateCategories::isStatus($status)) {
                $status = CrateCategories::STATUS_OWNED;
            }

            // Purchase price + currency. The price column may carry currency
            // symbols / thousand separators from spreadsheets — the helpers
            // below strip them. A row that names a currency without a price
            // is treated as "no purchase price recorded" rather than an
            // error; the user probably forgot to fill in the amount.
            $priceCell = self::parsePurchasePriceCell(
                isset($row['purchasePrice']) ? (string) $row['purchasePrice'] : null,
            );
            if (isset($priceCell['error'])) {
                $skipped++;
                $errors[] = "Row {$rowNum}: {$priceCell['error']} — skipped";
                continue;
            }
            $purchasePrice    = $priceCell['price'] ?? null;
            $purchaseCurrency = null;
            if ($purchasePrice !== null) {
                $curCell = self::parsePurchaseCurrencyCell(
                    $row['purchasePriceCurrency'] ?? null,
                );
                if (isset($curCell['error'])) {
                    $skipped++;
                    $errors[] = "Row {$rowNum}: {$curCell['error']} — skipped";
                    continue;
                }
                $purchaseCurrency = $curCell['currency'] ?? null;
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
            $item->setPurchasePrice($purchasePrice);
            $item->setPurchasePriceCurrency($purchaseCurrency);
            $item->setCategory($category);
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $item->setCreatedAt($now);
            $item->setUpdatedAt($now);

            // Insert inside a savepoint so an unexpected DB failure on one
            // row (e.g. a value the up-front checks didn't anticipate) skips
            // just that row instead of poisoning the surrounding transaction
            // and discarding every already-processed row.
            $this->db->executeStatement('SAVEPOINT crate_import_row');
            try {
                $saved = $this->mapper->insert($item);
            } catch (\Throwable $e) {
                $this->db->executeStatement('ROLLBACK TO SAVEPOINT crate_import_row');
                $skipped++;
                $errors[] = "Row {$rowNum}: could not be saved — skipped";
                $this->logger->warning(
                    'Import row {row} for user {user} failed to insert: {msg}',
                    ['row' => $rowNum, 'user' => $userId, 'msg' => $e->getMessage(), 'app' => 'crate'],
                );
                continue;
            }
            $this->db->executeStatement('RELEASE SAVEPOINT crate_import_row');
            $existingKeys[$key] = true;
            $itemIds[] = $saved->getId();
            $created++;
        }

        try {
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info(
            'Import for user {user} ({cat}): {created} created, {dup} duplicates, {skip} skipped',
            [
                'user'    => $userId,
                'cat'     => $batchCategory,
                'created' => $created,
                'dup'     => $duplicates,
                'skip'    => $skipped,
                'app'     => 'crate',
            ],
        );

        return [
            'created'    => $created,
            'duplicates' => $duplicates,
            'skipped'    => $skipped,
            'errors'     => $errors,
            'itemIds'    => $itemIds,
        ];
    }

    private function dupKey(string $artist, string $title, string $format, string $category): string
    {
        return strtolower(trim($category)) . '||' . strtolower(trim($artist)) . '||'
            . strtolower(trim($title)) . '||' . strtolower(trim($format));
    }
}
