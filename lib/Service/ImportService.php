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
    /**
     * Recognised physical formats: lowercase match key => canonical spelling.
     * Downstream consumers (format filter chips, colour gradients,
     * EnrichmentService's format-aware release matching) compare format strings
     * exactly, so an imported value is stored in the canonical spelling rather
     * than whatever casing the spreadsheet used. The canonical spellings must
     * stay in step with FORMAT_GROUPS in src/utils/categoryFormats.js.
     */
    private const VALID_FORMATS = [
        // Music — Vinyl
        'vinyl'                 => 'Vinyl',
        '7" single'             => '7" Single',
        '10"'                   => '10"',
        '12" single'            => '12" Single',
        'picture disc'          => 'Picture Disc',
        'flexi-disc'            => 'Flexi-disc',
        'shellac'               => 'Shellac',
        'lathe cut'             => 'Lathe Cut',
        // Music — Tape
        'cassette'              => 'Cassette',
        '8-track'               => '8-Track',
        'reel-to-reel'          => 'Reel-to-Reel',
        'dat'                   => 'DAT',
        'dcc'                   => 'DCC',
        '4-track cartridge'     => '4-Track Cartridge',
        'microcassette'         => 'Microcassette',
        // Music — Disc
        'cd'                    => 'CD',
        'sacd'                  => 'SACD',
        'cd-r'                  => 'CD-R',
        'shm-cd'                => 'SHM-CD',
        'hdcd'                  => 'HDCD',
        'cdv'                   => 'CDV',
        'blu-ray audio'         => 'Blu-ray Audio',
        'dvd-audio'             => 'DVD-Audio',
        'laserdisc'             => 'LaserDisc',
        'minidisc'              => 'MiniDisc',
        // Films
        'blu-ray'               => 'Blu-ray',
        '4k uhd'                => '4K UHD',
        '3d blu-ray'            => '3D Blu-ray',
        'dvd'                   => 'DVD',
        'hd dvd'                => 'HD DVD',
        'vhs'                   => 'VHS',
        'vcd'                   => 'VCD',
        'betamax'               => 'Betamax',
        // Books
        'hardcover'             => 'Hardcover',
        'paperback'             => 'Paperback',
        'mass market paperback' => 'Mass Market Paperback',
        'trade paperback'       => 'Trade Paperback',
        'graphic novel'         => 'Graphic Novel',
        'comic'                 => 'Comic',
        'audiobook cd'          => 'Audiobook CD',
        'audiobook cassette'    => 'Audiobook Cassette',
        // Games — Sony
        'ps5'                   => 'PS5',
        'ps4'                   => 'PS4',
        'ps3'                   => 'PS3',
        'ps2'                   => 'PS2',
        'ps1'                   => 'PS1',
        'ps vita'               => 'PS Vita',
        'psp'                   => 'PSP',
        // Games — Microsoft
        'xbox series x|s'       => 'Xbox Series X|S',
        'xbox one'              => 'Xbox One',
        'xbox 360'              => 'Xbox 360',
        'xbox'                  => 'Xbox',
        // Games — Nintendo
        'switch 2'              => 'Switch 2',
        'switch'                => 'Switch',
        'wii u'                 => 'Wii U',
        'wii'                   => 'Wii',
        'gamecube'              => 'GameCube',
        'n64'                   => 'N64',
        'snes'                  => 'SNES',
        'nes'                   => 'NES',
        '3ds'                   => '3DS',
        'ds'                    => 'DS',
        'game boy advance'      => 'Game Boy Advance',
        'game boy color'        => 'Game Boy Color',
        'game boy'              => 'Game Boy',
        'virtual boy'           => 'Virtual Boy',
        // Games — Sega
        'dreamcast'             => 'Dreamcast',
        'saturn'                => 'Saturn',
        'mega drive / genesis'  => 'Mega Drive / Genesis',
        'master system'         => 'Master System',
        'game gear'             => 'Game Gear',
        'sega cd'               => 'Sega CD',
        'sega 32x'              => 'Sega 32X',
        // Games — Atari
        'atari 2600'            => 'Atari 2600',
        'atari 5200'            => 'Atari 5200',
        'atari 7800'            => 'Atari 7800',
        'atari lynx'            => 'Atari Lynx',
        'jaguar'                => 'Jaguar',
        // Games — SNK
        'neo geo mvs'           => 'Neo Geo MVS',
        'neo geo aes'           => 'Neo Geo AES',
        'neo geo cd'            => 'Neo Geo CD',
        'neo geo pocket color'  => 'Neo Geo Pocket Color',
        // Comics — Single Issues
        'single issue'          => 'Single Issue',
        'annual'                => 'Annual',
        'special'               => 'Special',
        'one-shot'              => 'One-Shot',
        'mini-series'           => 'Mini-Series',
        'limited series'        => 'Limited Series',
        // Comics — Collected
        'omnibus'               => 'Omnibus',
        'compendium'            => 'Compendium',
    ];

    /**
     * Length caps in CHARACTERS for the bounded varchar columns, matching the
     * widths declared in Version0001Date20260421000000. A row exceeding any of
     * them is skipped with a clear error instead of overflowing the column at
     * insert time. Only bounded columns belong here — `notes` is TEXT and has
     * no width to overflow.
     */
    private const MAX_LEN = [
        'artist'    => 500,
        'title'     => 500,
        'format'    => 50,
        'barcode'   => 50,
        'label'     => 500,
        'discogsId' => 50,
    ];

    /**
     * Inflated-size budget for a single XLSX part. The compressed upload cap
     * says nothing about what the parts expand to, and an inflated part is
     * held whole in memory: without this a 10 MB archive can exhaust
     * memory_limit, which is a fatal error that takes the php-fpm worker with
     * it. Paired with MAX_XLSX_RATIO so a highly compressible part is rejected
     * on its expansion factor as well as its absolute size.
     */
    private const MAX_XLSX_MEMBER_BYTES = 32 * 1024 * 1024;

    /** Highest uncompressed:compressed ratio accepted for an XLSX part. */
    private const MAX_XLSX_RATIO = 100;

    /** Inflated size above which the compression ratio is also checked. */
    private const MIN_XLSX_RATIO_CHECK_BYTES = 1024 * 1024;

    /** Hard cap on rows accepted in one import. */
    private const MAX_IMPORT_ROWS = 20000;

    /** Column name aliases → canonical field name */
    public const ALIASES = [
        // Artist-equivalent across categories
        'artist'          => 'artist',
        'author'          => 'artist',
        'director'        => 'artist',
        'developer'       => 'artist',
        'writer'          => 'artist',
        // Title — including the per-category headers ExportService emits
        // and the per-category field labels the UI shows
        'album'           => 'title',
        'title'           => 'title',
        'album / title'   => 'title',
        'album/title'     => 'title',
        'film title'      => 'title',
        'game title'      => 'title',
        'book title'      => 'title',
        'series / volume' => 'title',
        'series/volume'   => 'title',
        'series / volume title' => 'title',
        'series/volume title'   => 'title',
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
     * Each row is keyed by its offset from the header line, so a blank line
     * mid-file leaves a gap instead of pulling the rows below it up a line —
     * that key is what import() reports as the row number.
     *
     * @return array{headers: string[], rows: array<int, array<string|null>>}
     * @throws \RuntimeException on parse failure
     */
    public function parseFile(string $tmpPath, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($ext === 'csv') {
            return $this->parseCsv($tmpPath);
        }

        // Only OOXML. parseXlsx() reads `xl/sharedStrings.xml` and
        // `xl/worksheets/sheet1.xml` out of a zip, which a binary .xls (an OLE2
        // compound file) and an .ods (a zip holding `content.xml`) never carry,
        // so routing either here produced a parse error rather than a refusal.
        if ($ext === 'xlsx') {
            return $this->parseXlsx($tmpPath);
        }

        throw new \RuntimeException("Unsupported file type: .{$ext}");
    }

    /** @return array{headers: string[], rows: array<int, array<string|null>>} */
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
        $lineNo = 0;
        // Escaping is disabled so parsing follows RFC 4180, which is what every
        // spreadsheet produces: a quote is escaped by doubling it and nothing
        // else is special. PHP's historical default treats a backslash as an
        // escape, so a value ending in one — a Windows path, an artist written
        // "AC\DC\" — hides the closing quote and swallows every following row
        // into that cell.
        while (($line = fgetcsv($tmp, escape: '')) !== false) {
            $lineNo++;
            if (empty($headers)) {
                $headers = array_map('trim', array_map('strval', $line));
            } else {
                // Skip blank lines (all-empty or single-null-element rows), but
                // key what survives by its offset from the header line: the
                // row number reported back to the user is derived from that
                // key, and a compacted list would name the wrong line.
                $nonEmpty = array_filter($line, fn($v) => $v !== null && $v !== '');
                if (!empty($nonEmpty)) {
                    $rows[$lineNo - 2] = array_map(fn($v) => $v !== '' ? $v : null, $line);
                }
            }
        }
        fclose($tmp);
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Parse XLSX using ZipArchive + SimpleXML. Handles the standard Office
     * Open XML format; .xls and .ods have to be re-saved as .xlsx first.
     *
     * @return array{headers: string[], rows: array<int, array<string|null>>}
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

        try {
            $ssXml    = $this->readZipMember($zip, 'xl/sharedStrings.xml');
            $sheetXml = $this->readZipMember($zip, 'xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
        }

        // Shared strings (text cells are stored by index)
        $sharedStrings = [];
        if ($ssXml !== false) {
            $ss = $this->parseXmlSafe($ssXml);
            if ($ss !== null) {
                foreach ($ss->si as $si) {
                    $sharedStrings[] = $this->sharedStringText($si);
                }
            }
        }

        if ($sheetXml === false) {
            throw new \RuntimeException('Could not read worksheet from spreadsheet');
        }

        $sheet = $this->parseXmlSafe($sheetXml);
        if ($sheet === null) {
            throw new \RuntimeException('Could not parse worksheet XML');
        }

        $headers = [];
        $rows = [];
        $nextIdx = 0;

        $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetRows = $sheet->xpath('//x:row') ?: [];

        foreach ($sheetRows as $row) {
            $rowData = [];

            foreach ($row->c as $cell) {
                // Parse column index from cell reference (e.g. "C5" → col 2).
                // The `r` attribute is optional in OOXML (LibreOffice omits it
                // for dense rows), in which case the cell belongs in the next
                // free column.
                $ref = (string)($cell['r'] ?? '');
                preg_match('/^([A-Z]+)/', $ref, $m);
                $colIdx = isset($m[1])
                    ? $this->colLetterToIndex($m[1])
                    : count($rowData);

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
                // strval first: a sparse row legitimately holds nulls, and
                // trim(null) is deprecated.
                $headers = array_map('trim', array_map('strval', $rowData));
            } else {
                // Offset from the header line, taken from the row's own `r`
                // reference: a wholly empty row is left out of sheetData
                // altogether, and a running counter would then report every
                // later row one line early. `r` is optional, so the counter
                // stays on as a monotonic floor.
                $sheetRow   = (int)(string)($row['r'] ?? '');
                $idx        = max($nextIdx, $sheetRow > 1 ? $sheetRow - 2 : 0);
                $rows[$idx] = $rowData;
                $nextIdx    = $idx + 1;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Flatten one <si> entry of the shared-string table. A plain string holds a
     * single <t>; a formatted one is split into <r> runs each with their own
     * <t>. Element access is used rather than XPath because the part declares a
     * default namespace, which an unprefixed XPath step never matches.
     */
    private function sharedStringText(\SimpleXMLElement $si): string
    {
        $text = '';
        foreach ($si->t as $t) {
            $text .= (string)$t;
        }
        foreach ($si->r as $run) {
            foreach ($run->t as $t) {
                $text .= (string)$t;
            }
        }
        return $text;
    }

    /**
     * Read one member of an already-open XLSX archive, refusing to inflate a
     * part that declares an implausible uncompressed size or compression
     * ratio. Returns false when the member is absent.
     *
     * @throws \RuntimeException when the member exceeds the inflation budget
     */
    private function readZipMember(\ZipArchive $zip, string $name): string|false
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return false;
        }
        $declared   = (int)($stat['size'] ?? 0);
        $compressed = (int)($stat['comp_size'] ?? 0);
        if ($declared > self::MAX_XLSX_MEMBER_BYTES) {
            throw new \RuntimeException('Spreadsheet contents too large to process');
        }
        // Only worth ratio-checking a part big enough to matter once inflated:
        // a small part cannot exhaust memory however well it compressed.
        if (
            $declared > self::MIN_XLSX_RATIO_CHECK_BYTES
            && $compressed > 0
            && $declared > $compressed * self::MAX_XLSX_RATIO
        ) {
            throw new \RuntimeException('Spreadsheet rejected: implausible compression ratio');
        }
        return $zip->getFromName($name);
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
     * Row keys are carried through untouched: each holds the row's offset from
     * the header line, which import() turns back into the row number it
     * reports, so re-indexing here would move every error message.
     *
     * @param  array<int, array<string|null>> $rows
     * @param  array<int, string|null>        $mapping
     * @return array<int, array<string, string|null>>
     */
    public static function applyMapping(array $rows, array $mapping): array
    {
        $result = [];
        foreach ($rows as $idx => $row) {
            $item = [];
            foreach ($mapping as $colIdx => $field) {
                if ($field === null) {
                    continue;
                }
                $item[$field] = isset($row[$colIdx]) ? trim((string)$row[$colIdx]) : null;
            }
            $result[$idx] = $item;
        }
        return $result;
    }

    /**
     * Validate and import rows for a user. Returns a summary.
     *
     * $allowRowCategoryOverride must be false whenever write access was
     * authorised for $batchCategory alone — a caller holding a read/write
     * share of one category of someone else's library could otherwise map a
     * Category column and place rows in the owner's other collections.
     *
     * @param  array<int, array<string, string|null>> $mappedRows
     * @param  string                                 $userId
     * @return array{created: int, duplicates: int, skipped: int, errors: string[], itemIds: int[]}
     */
    public function import(
        array $mappedRows,
        string $userId,
        string $batchCategory = CrateCategories::MUSIC,
        bool $allowRowCategoryOverride = true,
    ): array {
        $created    = 0;
        $duplicates = 0;
        $skipped    = 0;
        $errors     = [];
        $itemIds    = [];

        $rowCount = count($mappedRows);
        if ($rowCount > self::MAX_IMPORT_ROWS) {
            return [
                'created'    => 0,
                'duplicates' => 0,
                'skipped'    => $rowCount,
                'errors'     => [
                    "Too many rows ({$rowCount}) — the limit is "
                        . self::MAX_IMPORT_ROWS . ' rows per import',
                ],
                'itemIds'    => [],
            ];
        }

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
            // Keys hold each row's offset from the header line, so a blank
            // line mid-file no longer shifts what the errors below name.
            $rowNum = $i + 2;

            // Per-row category override (e.g. from a re-imported export with Category column)
            $category = $batchCategory;
            if ($allowRowCategoryOverride) {
                $rowCategoryRaw = strtolower(trim((string)($row['category'] ?? '')));
                if (CrateCategories::isCategory($rowCategoryRaw)) {
                    $category = $rowCategoryRaw;
                }
            }

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

            // Validate format value, then adopt the canonical spelling
            $formatKey = strtolower(trim($format));
            if (!isset(self::VALID_FORMATS[$formatKey])) {
                $skipped++;
                $errors[] = "Row {$rowNum}: unrecognised format \"{$format}\" - skipped";
                continue;
            }
            $format = self::VALID_FORMATS[$formatKey];

            // Length validation — the DB truncates silently, so reject up-front
            // to make the user aware of the data loss. The caps are column
            // widths in characters, so measure characters and not bytes.
            $overLen = null;
            foreach (self::MAX_LEN as $field => $max) {
                $value = (string)($row[$field] ?? '');
                if (mb_strlen($value, 'UTF-8') > $max) {
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
            $year      = null;
            $yearRaw   = trim((string)($row['year'] ?? ''));
            if ($yearRaw !== '') {
                // Only a plausible 4-digit year is stored: casting free text
                // ("unknown", "c. 1985", "?") yields year 0, which renders as
                // "0" and adds a bogus decade to the filter list.
                $maxYear = (int)date('Y') + 1;
                if (ctype_digit($yearRaw) && (int)$yearRaw >= 1000 && (int)$yearRaw <= $maxYear) {
                    $year = (int)$yearRaw;
                } else {
                    $errors[] = "Row {$rowNum}: unrecognised year \"{$yearRaw}\" — imported without a year";
                }
            }
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
