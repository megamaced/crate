<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Service\ExportService;
use PHPUnit\Framework\TestCase;

/**
 * The "all" export puts both price sources in one sheet, and `market_value`
 * means different things to each: a PriceCharting CIB price for a game or
 * comic, a Discogs asking price for a record. Filling both columns from it
 * invents a CIB price for every album — a number the user could act on, in a
 * column whose whole point is to say where the figure came from.
 */
class ExportMarketColumnsTest extends TestCase
{
    public function testMusicRowInTheAllExportFillsOnlyTheDiscogsMarketValue(): void
    {
        $row = $this->row($this->music(), null);

        self::assertSame('12.5', $row['Market Value']);
        self::assertSame('', $row['CIB Price'], 'CIB is a PriceCharting tier, and music is priced by Discogs');
        self::assertSame('', $row['Loose Price']);
        self::assertSame('', $row['New Price']);
    }

    public function testGameRowInTheAllExportFillsOnlyThePriceChartingTiers(): void
    {
        $row = $this->row($this->game(), null);

        self::assertSame('20', $row['Loose Price']);
        self::assertSame('30', $row['CIB Price']);
        self::assertSame('40', $row['New Price']);
        self::assertSame('', $row['Market Value'], 'Market Value is the Discogs figure, and a game has none');
    }

    public function testBothRowsOfTheAllExportKeepTheSharedMarketMetadata(): void
    {
        self::assertSame('GBP', $this->row($this->music(), null)['Market Currency']);
        self::assertSame('USD', $this->row($this->game(), null)['Market Currency']);
    }

    public function testSingleCategoryExportsOnlyCarryTheirOwnPriceColumns(): void
    {
        $music = $this->row($this->music(), 'music');
        self::assertSame('12.5', $music['Market Value']);
        self::assertArrayNotHasKey('CIB Price', $music);

        $game = $this->row($this->game(), 'game');
        self::assertSame('30', $game['CIB Price']);
        self::assertArrayNotHasKey('Market Value', $game);
    }

    /**
     * One market-value export row, keyed by the header it sits under.
     * ExportService::buildHeaders and ::itemToRow are private, and the export
     * only needs its mapper for the item list, so drive the pair directly.
     *
     * @return array<string, string>
     */
    private function row(MediaItem $item, ?string $category): array
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $service    = $reflection->newInstanceWithoutConstructor();

        /** @var string[] $headers */
        $headers = $reflection->getMethod('buildHeaders')->invoke($service, false, true, $category, false);
        /** @var string[] $cells */
        $cells = $reflection->getMethod('itemToRow')->invoke($service, $item, false, true, $category, false);

        self::assertSameSize($headers, $cells, 'every row has to line up with the header');
        return array_combine($headers, $cells);
    }

    private function music(): MediaItem
    {
        $item = new MediaItem();
        $item->setId(1);
        $item->setTitle('Kind of Blue');
        $item->setArtist('Miles Davis');
        $item->setFormat('Vinyl');
        $item->setStatus('owned');
        $item->setCategory('music');
        $item->setMarketValue(12.5);
        $item->setMarketValueCurrency('GBP');
        return $item;
    }

    private function game(): MediaItem
    {
        $item = new MediaItem();
        $item->setId(2);
        $item->setTitle('Chrono Trigger');
        $item->setArtist('Square');
        $item->setFormat('SNES');
        $item->setStatus('owned');
        $item->setCategory('game');
        $item->setMarketValueLoose(20.0);
        $item->setMarketValue(30.0);
        $item->setMarketValueNew(40.0);
        $item->setMarketValueCurrency('USD');
        return $item;
    }
}
