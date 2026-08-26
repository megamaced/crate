<?php

declare(strict_types=1);

namespace OCA\Crate;

/**
 * Shared constants for the five supported item categories plus the two
 * allowed item statuses. Promoted from scattered `private const VALID_*`
 * declarations in MediaController / ImportController / ImportService so
 * that adding a new category happens in one place.
 */
final class CrateCategories
{
    public const MUSIC = 'music';
    public const FILM  = 'film';
    public const BOOK  = 'book';
    public const GAME  = 'game';
    public const COMIC = 'comic';

    /** @var list<string> */
    public const ALL = [
        self::MUSIC,
        self::FILM,
        self::BOOK,
        self::GAME,
        self::COMIC,
    ];

    public const STATUS_OWNED  = 'owned';
    public const STATUS_WANTED = 'wanted';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_OWNED, self::STATUS_WANTED];

    /**
     * Categories that have a market-value source: music via Discogs, game/comic
     * via PriceCharting. Films and books have none — the `discogs_id` column is
     * shared across categories as a generic enrichment id, so feeding film/book
     * rows into the market-value path treats a TMDB / Open Library id as a
     * Discogs release id and stores unrelated prices on the wrong item.
     *
     * @var list<string>
     */
    public const MARKET_CATEGORIES = [self::MUSIC, self::GAME, self::COMIC];

    /**
     * Categories priced by PriceCharting, which quotes three tiers
     * (loose / CIB / new) in USD. The remaining market category, music, is
     * priced by Discogs as a single value in the user's display currency —
     * so a tier column only ever carries a value for these two.
     *
     * @var list<string>
     */
    public const PRICECHARTING_CATEGORIES = [self::GAME, self::COMIC];

    public static function hasMarketValue(string $category): bool
    {
        return in_array($category, self::MARKET_CATEGORIES, true);
    }

    public static function usesPriceCharting(string $category): bool
    {
        return in_array($category, self::PRICECHARTING_CATEGORIES, true);
    }

    public static function isCategory(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    public static function isStatus(string $value): bool
    {
        return in_array($value, self::STATUSES, true);
    }

    private function __construct()
    {
    }
}
