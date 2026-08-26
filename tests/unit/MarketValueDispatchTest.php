<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\PriceChartingService;
use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which price source an item goes to is decided from its category alone, and
 * the two sources are not interchangeable: PriceCharting is searched by title
 * and platform, while Discogs is looked up by release id. Sending a category
 * to the wrong one stores a stranger's price, so the split is pinned here as
 * well as in the category constants it now reads.
 */
#[AllowMockObjectsWithoutExpectations]
class MarketValueDispatchTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function priceChartingCategoryProvider(): array
    {
        return ['game' => ['game'], 'comic' => ['comic']];
    }

    #[DataProvider('priceChartingCategoryProvider')]
    public function testGamesAndComicsGoToPriceCharting(string $category): void
    {
        $priceCharting = $this->createMock(PriceChartingService::class);
        $priceCharting->expects(self::once())
            ->method('searchAndFetchPrices')
            ->with('alice', 'Chrono Trigger', 'SNES')
            ->willReturn(null);

        self::assertNull($this->service($category, $priceCharting)->fetchAndStore(1, 'alice', 'GBP'));
    }

    public function testMusicNeverGoesToPriceCharting(): void
    {
        $priceCharting = $this->createMock(PriceChartingService::class);
        $priceCharting->expects(self::never())->method('searchAndFetchPrices');

        // No Discogs release id, so the Discogs path has nothing to look up
        // either and stops before making a request.
        self::assertNull($this->service('music', $priceCharting)->fetchAndStore(1, 'alice', 'GBP'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sourcelessCategoryProvider(): array
    {
        return ['film' => ['film'], 'book' => ['book']];
    }

    #[DataProvider('sourcelessCategoryProvider')]
    public function testFilmsAndBooksReachNoPriceSource(string $category): void
    {
        $priceCharting = $this->createMock(PriceChartingService::class);
        $priceCharting->expects(self::never())->method('searchAndFetchPrices');

        self::assertNull($this->service($category, $priceCharting)->fetchAndStore(1, 'alice', 'GBP'));
    }

    /** A service over one un-priced item of $category. */
    private function service(string $category, PriceChartingService $priceCharting): MarketValueService
    {
        $item = new MediaItem();
        $item->setId(1);
        $item->setUserId('alice');
        $item->setTitle('Chrono Trigger');
        $item->setFormat('SNES');
        $item->setCategory($category);

        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findWritableForUser')->willReturn($item);
        $mapper->expects(self::never())->method('update');

        return new MarketValueService(
            $mapper,
            $this->createMock(IClientService::class),
            $this->createMock(ICredentialsManager::class),
            $priceCharting,
        );
    }
}
