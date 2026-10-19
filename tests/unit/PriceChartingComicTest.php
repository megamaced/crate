<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\PriceChartingService;
use PHPUnit\Framework\TestCase;

/**
 * PriceCharting files a comic under its series — console-name "Comic Books
 * Spawn" — and names each product "Series [Variant] #Issue (Year)". The
 * fixtures below follow that shape, as the provider returns it for a search
 * like "Spawn #1"; the item's format (Single Issue, Omnibus) appears nowhere
 * in it, so matching on format can never succeed.
 */
class PriceChartingComicTest extends TestCase
{
    /** @return list<array<string, string>> */
    private function spawn(): array
    {
        return [
            ['priceChartingId' => '1001', 'title' => 'Crusade of Comics Presents Spawn Mini #1 (1992)',
                'platform' => 'Comic Books Spawn'],
            ['priceChartingId' => '2332891', 'title' => 'Spawn #1 (1992)', 'platform' => 'Comic Books Spawn'],
            ['priceChartingId' => '1003', 'title' => 'Spawn [Misprint] #1 (1992)', 'platform' => 'Comic Books Spawn'],
            ['priceChartingId' => '1004', 'title' => 'Spawn [Newsstand] #1 (1992)', 'platform' => 'Comic Books Spawn'],
            ['priceChartingId' => '1005', 'title' => 'Spawn #10 (1993)', 'platform' => 'Comic Books Spawn'],
            ['priceChartingId' => '1006', 'title' => 'King Spawn #1 (2021)', 'platform' => 'Comic Books King Spawn'],
            ['priceChartingId' => '1007', 'title' => 'Spawn', 'platform' => 'Playstation'],
        ];
    }

    private function pick(string $title, ?string $format = 'Single Issue', ?int $year = null): ?string
    {
        $match = PriceChartingService::pickComic($this->spawn(), $title, $format, $year);
        return $match === null ? null : $match['priceChartingId'];
    }

    public function testASingleIssueMatchesItsSeriesAndIssueNotItsFormat(): void
    {
        self::assertSame('2332891', $this->pick('Spawn #1'));
        self::assertSame('2332891', $this->pick('Spawn #001'));
        self::assertSame('1005', $this->pick('Spawn #10'));
    }

    public function testTheGameFormatMatcherRejectsTheSameComic(): void
    {
        // The defect: a comic's physical format compared with a series name.
        self::assertNull(PriceChartingService::pickByPlatform([$this->spawn()[1]], 'Single Issue'));
    }

    public function testANamedVariantIsMatchedAndThePlainEditionIsPreferredOtherwise(): void
    {
        self::assertSame('1004', $this->pick('Spawn [Newsstand] #1'));
        self::assertSame('1003', $this->pick('Spawn #1 [Misprint]'));
        self::assertNull($this->pick('Spawn [Polybagged] #1'));
    }

    public function testOtherSeriesAndNonComicsAreNotMatched(): void
    {
        self::assertSame('1006', $this->pick('King Spawn #1'));
        self::assertNull($this->pick('Spawn #2'));
        // A title without an issue number names no single issue in this list,
        // and the PlayStation game of the same name is not a comic.
        self::assertNull($this->pick('Spawn'));
    }

    public function testAnUnnamedSpecialVariantIsNeverTakenForThePlainEdition(): void
    {
        $newsstandOnly = [$this->spawn()[3]];

        self::assertNull(PriceChartingService::pickComic($newsstandOnly, 'Spawn #1', 'Single Issue', null));
        $named = PriceChartingService::pickComic($newsstandOnly, 'Spawn [Newsstand] #1', 'Single Issue', null);
        self::assertSame('1004', $named['priceChartingId']);
    }

    public function testTheYearSettlesARelaunch(): void
    {
        $results = [
            ['priceChartingId' => '1', 'title' => 'Batman #1 (1940)', 'platform' => 'Comic Books Batman'],
            ['priceChartingId' => '2', 'title' => 'Batman #1 (2016)', 'platform' => 'Comic Books Batman'],
        ];

        $rebirth = PriceChartingService::pickComic($results, 'Batman #1', 'Single Issue', 2016);
        $golden  = PriceChartingService::pickComic($results, 'Batman #1 (1940)', null, 2016);
        self::assertSame('2', $rebirth['priceChartingId']);
        self::assertSame('1', $golden['priceChartingId']);
        // No year to go on, or one that matches neither: ambiguous, not the first.
        self::assertNull(PriceChartingService::pickComic($results, 'Batman #1', 'Single Issue', null));
        self::assertNull(PriceChartingService::pickComic($results, 'Batman #1', 'Single Issue', 2011));
    }

    public function testAKnownYearMustMatchEvenWhenOnlyOneProductFits(): void
    {
        $rebirthOnly = [
            ['priceChartingId' => '2', 'title' => 'Batman #1 (2016)', 'platform' => 'Comic Books Batman'],
        ];

        // The title's own year, and the item's stored year, are both binding:
        // a truncated search page holding one Batman #1 does not make it 1940's.
        self::assertNull(PriceChartingService::pickComic($rebirthOnly, 'Batman #1 (1940)', 'Single Issue', null));
        self::assertNull(PriceChartingService::pickComic($rebirthOnly, 'Batman #1', 'Single Issue', 1940));
        self::assertNull($this->pick('Spawn #10', 'Single Issue', 1992));
        self::assertSame('1005', $this->pick('Spawn #10', 'Single Issue', 1993));
    }

    public function testACollectedEditionIsChosenByTheBindingItsFormatNames(): void
    {
        $results = [
            ['priceChartingId' => '1', 'title' => 'Spawn: Origins [Paperback] (2009)',
                'platform' => 'Comic Books Spawn'],
            ['priceChartingId' => '2', 'title' => 'Spawn: Origins [Hardcover] (2009)',
                'platform' => 'Comic Books Spawn'],
        ];

        $hardcover = PriceChartingService::pickComic($results, 'Spawn: Origins', 'Hardcover', 2009);
        $paperback = PriceChartingService::pickComic($results, 'Spawn: Origins', 'Trade Paperback', 2009);
        self::assertSame('2', $hardcover['priceChartingId']);
        self::assertSame('1', $paperback['priceChartingId']);
        // An omnibus or graphic novel comes in either binding, so neither is guessed.
        self::assertNull(PriceChartingService::pickComic($results, 'Spawn: Origins', 'Omnibus', 2009));
        self::assertNull(PriceChartingService::pickComic($results, 'Spawn: Origins', null, 2009));
    }

    public function testTheOtherBindingIsNeverAccepted(): void
    {
        $hardcover = ['priceChartingId' => 'hc', 'title' => 'Watchmen [Hardcover] (2009)',
            'platform' => 'Comic Books Watchmen'];
        $reissue   = ['priceChartingId' => 'plain', 'title' => 'Watchmen (2019)', 'platform' => 'Comic Books Watchmen'];

        self::assertNull(PriceChartingService::pickComic([$hardcover], 'Watchmen', 'Trade Paperback', 2009));
        // The year is identity and is applied before the unmarked edition is
        // considered, so the 2019 printing cannot stand in for 2009's.
        self::assertSame(
            'hc',
            PriceChartingService::pickComic([$reissue, $hardcover], 'Watchmen', 'Hardcover', 2009)['priceChartingId'],
        );
        $omnibus = ['priceChartingId' => 'omni', 'title' => 'The Boys Omnibus [Paperback] Vol 1 (2019)',
            'platform' => 'Comic Books The Boys'];
        self::assertNull(PriceChartingService::pickComic([$omnibus], 'The Boys Omnibus Vol 1', 'Omnibus', 2019));
    }
}
