<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\CrateCategories;
use PHPUnit\Framework\TestCase;

/**
 * Sanity tests on the central category / status constants. Cheap to run
 * and catches accidental regressions when adding / removing categories.
 */
class CrateCategoriesTest extends TestCase
{
    public function testAllFiveCategoriesAreListed(): void
    {
        self::assertSame(
            ['music', 'film', 'book', 'game', 'comic'],
            CrateCategories::ALL,
        );
    }

    public function testBothStatusesAreListed(): void
    {
        self::assertSame(['owned', 'wanted'], CrateCategories::STATUSES);
    }

    public function testIsCategoryAcceptsKnownValues(): void
    {
        foreach (CrateCategories::ALL as $cat) {
            self::assertTrue(CrateCategories::isCategory($cat), $cat);
        }
    }

    public function testIsCategoryRejectsUnknown(): void
    {
        self::assertFalse(CrateCategories::isCategory(''));
        self::assertFalse(CrateCategories::isCategory('movie'));
        self::assertFalse(CrateCategories::isCategory('MUSIC')); // case-sensitive
        self::assertFalse(CrateCategories::isCategory(' music'));
    }

    public function testIsStatusAcceptsKnownValues(): void
    {
        self::assertTrue(CrateCategories::isStatus('owned'));
        self::assertTrue(CrateCategories::isStatus('wanted'));
    }

    public function testIsStatusRejectsUnknown(): void
    {
        self::assertFalse(CrateCategories::isStatus(''));
        self::assertFalse(CrateCategories::isStatus('pending'));
        self::assertFalse(CrateCategories::isStatus('OWNED'));
    }

    public function testOnlyMusicGamesAndComicsHaveAMarketValue(): void
    {
        self::assertSame(['music', 'game', 'comic'], CrateCategories::MARKET_CATEGORIES);
        self::assertFalse(CrateCategories::hasMarketValue('film'));
        self::assertFalse(CrateCategories::hasMarketValue('book'));
        foreach (CrateCategories::MARKET_CATEGORIES as $cat) {
            self::assertTrue(CrateCategories::hasMarketValue($cat), $cat);
        }
    }

    public function testOnlyGamesAndComicsArePricedByPriceCharting(): void
    {
        self::assertSame(['game', 'comic'], CrateCategories::PRICECHARTING_CATEGORIES);
        self::assertFalse(CrateCategories::usesPriceCharting('music'), 'music is priced by Discogs');
        foreach (CrateCategories::PRICECHARTING_CATEGORIES as $cat) {
            self::assertTrue(CrateCategories::usesPriceCharting($cat), $cat);
        }
    }

    public function testEveryPriceChartingCategoryIsAMarketCategory(): void
    {
        // The export's price columns and MarketValueService's dispatch both
        // read these two lists; a category priced by PriceCharting that isn't
        // a market category at all would never reach either.
        foreach (CrateCategories::PRICECHARTING_CATEGORIES as $cat) {
            self::assertContains($cat, CrateCategories::MARKET_CATEGORIES, $cat);
        }
    }
}
