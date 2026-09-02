<?php

declare(strict_types=1);

namespace OCA\Crate\Dto;

/**
 * Value object for media item create/update payloads.
 *
 * Replaces the 12-parameter method signatures with a single typed argument,
 * and is the one place a write payload is checked against what the schema can
 * actually hold. Without that check the API and the import path disagree:
 * ImportService rejects a blank or over-long row up front (see its own
 * MAX_LEN), while a direct API client's oversized string reached the driver —
 * surfacing as a 500 on PostgreSQL and strict MySQL, as silent truncation on
 * non-strict MySQL, and as an unbounded value on SQLite.
 *
 * The caps below are the column widths declared in
 * Version0001Date20260421000000, measured in characters because that is what
 * the column counts. Keep the two in lock-step.
 */
class MediaItemData
{
    /** Column widths, in characters, for the fields a writer supplies. */
    private const MAX_LEN = [
        'title'                 => 500,
        'artist'                => 500,
        'format'                => 50,
        'barcode'               => 50,
        'discogsId'             => 50,
        'artworkPath'           => 1000,
        'label'                 => 500,
        'country'               => 100,
        'status'                => 10,
        'category'              => 16,
        'purchasePriceCurrency' => 3,
    ];

    /**
     * Earliest year accepted, matching ImportService. A plain INTEGER column
     * overflows above the upper bound, and a value outside the range is free
     * text that got cast rather than a publication year.
     */
    private const MIN_YEAR = 1000;

    /** Trimmed: the three NOT NULL columns the forms and the import require. */
    public readonly string $title;
    public readonly string $artist;
    public readonly string $format;

    public function __construct(
        string $title,
        string $artist,
        string $format,
        public readonly ?int $year = null,
        public readonly ?string $barcode = null,
        public readonly ?string $notes = null,
        public readonly string $status = 'owned',
        public readonly ?string $discogsId = null,
        public readonly ?string $artworkPath = null,
        public readonly ?string $label = null,
        public readonly ?string $country = null,
        public readonly ?string $category = null,
        /**
         * What the user paid for the item, in the user's chosen currency.
         * MediaService::update always overwrites with this value, so null
         * clears the stored price (and the controller pairs null/null when
         * the user empties the input). Currency is validated against the
         * allowlist in MediaController::normalisePurchasePrice.
         */
        public readonly ?float $purchasePrice = null,
        public readonly ?string $purchasePriceCurrency = null,
    ) {
        $this->title  = trim($title);
        $this->artist = trim($artist);
        $this->format = trim($format);

        // A whitespace-only value is a blank the caller did not notice sending;
        // the column is NOT NULL and every form already requires these.
        foreach (['title', 'artist', 'format'] as $field) {
            if ($this->$field === '') {
                throw new \InvalidArgumentException($field . ' is required');
            }
        }

        foreach (self::MAX_LEN as $field => $max) {
            $value = $this->$field;
            if (is_string($value) && mb_strlen($value, 'UTF-8') > $max) {
                throw new \InvalidArgumentException($field . ' exceeds ' . $max . ' characters');
            }
        }

        if ($year !== null && ($year < self::MIN_YEAR || $year > (int) date('Y') + 1)) {
            throw new \InvalidArgumentException('year out of range');
        }
    }
}
