<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\PriceChartingService;
use PHPUnit\Framework\TestCase;

/**
 * PriceCharting is searched by title, and a title like "Sonic the Hedgehog"
 * exists on a dozen platforms at wildly different prices. Taking whatever the
 * search ranks first stores a Switch price against a Mega Drive cartridge, so
 * the item's own format has to pick the product — and when nothing matches,
 * "no match" is the only honest answer.
 */
class PriceChartingPlatformTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function results(): array
    {
        return [
            ['priceChartingId' => '1', 'title' => 'Sonic the Hedgehog', 'platform' => 'Nintendo Switch'],
            ['priceChartingId' => '2', 'title' => 'Sonic the Hedgehog', 'platform' => 'GameBoy Advance'],
            ['priceChartingId' => '3', 'title' => 'Sonic the Hedgehog', 'platform' => 'Sega Mega Drive / Genesis'],
        ];
    }

    public function testFormatSelectsTheMatchingPlatform(): void
    {
        $match = PriceChartingService::pickByPlatform($this->results(), 'Mega Drive');
        self::assertSame('3', $match['priceChartingId']);
    }

    public function testEitherSideOfARegionalPairMatches(): void
    {
        $match = PriceChartingService::pickByPlatform($this->results(), 'Genesis');
        self::assertSame('3', $match['priceChartingId']);
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $match = PriceChartingService::pickByPlatform($this->results(), 'nintendo switch');
        self::assertSame('1', $match['priceChartingId']);
    }

    public function testNoMatchYieldsNothingRatherThanAnArbitraryProduct(): void
    {
        self::assertNull(PriceChartingService::pickByPlatform($this->results(), 'Dreamcast'));
    }

    public function testComicPrintingsAreDistinguishedTheSameWay(): void
    {
        $comics = [
            ['priceChartingId' => '10', 'title' => 'Saga', 'platform' => 'Omnibus'],
            ['priceChartingId' => '11', 'title' => 'Saga', 'platform' => 'Single Issue'],
        ];
        self::assertSame('11', PriceChartingService::pickByPlatform($comics, 'Single Issue')['priceChartingId']);
    }

    public function testWithoutAFormatTheFirstResultStands(): void
    {
        self::assertSame('1', PriceChartingService::pickByPlatform($this->results(), null)['priceChartingId']);
        self::assertSame('1', PriceChartingService::pickByPlatform($this->results(), '  ')['priceChartingId']);
    }

    public function testEmptyResultsYieldNothing(): void
    {
        self::assertNull(PriceChartingService::pickByPlatform([], null));
        self::assertNull(PriceChartingService::pickByPlatform([], 'Mega Drive'));
    }
}
