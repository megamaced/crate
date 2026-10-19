<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;

/**
 * What an item's market value was looked up by. Music is priced by its
 * Discogs release; games by title and platform; comics by title, year and
 * the binding their format names (see PriceChartingService::pickComic()).
 * Film and book have no price source, and a category change moves an item to
 * a different source altogether. A value fetched under one identity says
 * nothing about another.
 */
final class PricingIdentity
{
    /** @return list<string|int|null> */
    public static function of(MediaItem $item): array
    {
        $category = (string) $item->getCategory();
        $text = static fn(?string $value): string => mb_strtolower(trim((string) $value), 'UTF-8');
        return match ($category) {
            CrateCategories::MUSIC => [$category, trim((string) $item->getDiscogsId())],
            CrateCategories::GAME  => [$category, $text($item->getTitle()), $text($item->getFormat())],
            CrateCategories::COMIC => [
                $category,
                $text($item->getTitle()),
                $text($item->getFormat()),
                $item->getYear(),
            ],
            default => [$category],
        };
    }

    /**
     * Clear the market value when the item is no longer what it was priced as.
     * Left in place, the old release's or platform's price would be shown —
     * and totted up in collection value — as this item's, indefinitely: a
     * client only auto-fetches for an item with no value.
     *
     * @param list<string|int|null> $pricedAs of() before the change
     */
    public static function retireIfChanged(MediaItem $item, array $pricedAs): void
    {
        if (self::of($item) !== $pricedAs) {
            $item->retireMarketValue();
        }
    }

    private function __construct()
    {
    }
}
