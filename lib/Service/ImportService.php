<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\MediaItemMapper;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;

class ImportService
{
    /** Recognised physical formats (lowercase for matching) */
    private const VALID_FORMATS = [
        'vinyl', '7" single', '10"', '12" single', 'picture disc',
        'flexi-disc', 'shellac', 'lathe cut',
        'cassette', '8-track', 'reel-to-reel', 'dat', 'dcc',
        '4-track cartridge', 'microcassette',
        'cd', 'sacd', 'cd-r', 'shm-cd', 'hdcd', 'cdv',
        'blu-ray audio', 'dvd-audio', 'laserdisc', 'minidisc',
    ];

    /** Column name aliases → canonical field name */
    private const ALIASES = [
        'artist'   => 'artist',
        'album'    => 'title',
        'title'    => 'title',
        'format'   => 'format',
        'year'     => 'year',
        'notes'    => 'notes',
        'note'     => 'notes',
        'status'   => 'status',
        'discogsid' => 'discogsId',
        'discogs_id' => 'discogsId',
        'discogs id' => 'discogsId',
        'barcode'  => 'barcode',
        'label'    => 'label',
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

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->parseSpreadsheet($tmpPath);
        }

        if ($ext === 'ods') {
            return $this->parseOds($tmpPath);
        }

        throw new \RuntimeException("Unsupported file type: .{$ext}");
    }

    /** @return array{headers: string[], rows: array<array<string|null>>} */
    private function parseCsv(string $path): array
    {
        $options = new CsvOptions();
        $options->FIELD_DELIMITER = ',';
        $reader = new CsvReader($options);
        return $this->readWithOpenSpout($reader, $path);
    }

    /** @return array{headers: string[], rows: array<array<string|null>>} */
    private function parseSpreadsheet(string $path): array
    {
        $reader = new XlsxReader();
        return $this->readWithOpenSpout($reader, $path);
    }

    /** @return array{headers: string[], rows: array<array<string|null>>} */
    private function parseOds(string $path): array
    {
        $reader = new OdsReader();
        return $this->readWithOpenSpout($reader, $path);
    }

    /**
     * @return array{headers: string[], rows: array<array<string|null>>}
     */
    private function readWithOpenSpout(mixed $reader, string $path): array
    {
        $reader->open($path);

        $headers = [];
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(
                    fn($cell) => $cell->getValue() !== null ? (string)$cell->getValue() : null,
                    $row->getCells(),
                );
                if (empty($headers)) {
                    $headers = array_map('trim', array_map('strval', $cells));
                } else {
                    $rows[] = $cells;
                }
            }
            break; // first sheet only
        }

        $reader->close();
        return ['headers' => $headers, 'rows' => $rows];
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
    public function import(array $mappedRows, string $userId): array
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
                $errors[] = "Row {$rowNum}: unrecognised format "{$format}" — skipped";
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
