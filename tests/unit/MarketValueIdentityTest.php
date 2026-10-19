<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Dto\MediaItemData;
use OCA\Crate\Service\ActivityService;
use OCA\Crate\Service\MediaService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A market value belongs to what it was looked up by: a Discogs release, or a
 * game's title and platform, or a comic's title, year and binding. Editing the
 * release id from 101 to 102 used to keep release 101's price and fetch date
 * on the item for good, since clients only auto-fetch for an item with none.
 */
#[AllowMockObjectsWithoutExpectations]
class MarketValueIdentityTest extends TestCase
{
    private MediaItem $item;

    public function testChangingTheReleaseRetiresItsPrice(): void
    {
        $this->priced('music', 'Kind of Blue', 'Vinyl', '101');

        $this->service()->update(1, 'alice', $this->data(discogsId: '102'));

        $this->assertUnpriced();
        self::assertSame('102', $this->item->getDiscogsId());
    }

    public function testEditsThatKeepTheReleaseKeepItsPrice(): void
    {
        $this->priced('music', 'Kind of Blue', 'Vinyl', '101');

        $this->service()->update(1, 'alice', $this->data(title: 'Kind Of Blue (Remastered)', notes: 'Gatefold'));

        $this->assertPriced();
    }

    public function testChangingAGamesPlatformRetiresItsPrice(): void
    {
        $this->priced('game', 'Sonic the Hedgehog', 'Mega Drive', null);

        $this->service()->update(1, 'alice', $this->data(title: 'Sonic the Hedgehog', format: 'Game Gear'));

        $this->assertUnpriced();
    }

    public function testRecasingAGamesTitleKeepsItsPrice(): void
    {
        $this->priced('game', 'Sonic the Hedgehog', 'Mega Drive', null);

        $this->service()->update(1, 'alice', $this->data(title: 'Sonic The Hedgehog', format: 'Mega Drive'));

        $this->assertPriced();
    }

    public function testChangingAComicsYearRetiresItsPrice(): void
    {
        $this->priced('comic', 'Batman #1', 'Single Issue', null, 2016);

        $this->service()->update(1, 'alice', $this->data(title: 'Batman #1', format: 'Single Issue', year: 1940));

        $this->assertUnpriced();
    }

    public function testChangingCategoryRetiresThePrice(): void
    {
        $this->priced('game', 'Doom', 'PC', null);

        $this->service()->update(1, 'alice', $this->data(title: 'Doom', format: 'CD', category: 'music'));

        $this->assertUnpriced();
    }

    public function testStrippingEnrichmentRetiresAReleasesPrice(): void
    {
        $this->priced('music', 'Kind of Blue', 'Vinyl', '101');

        $this->service()->stripEnrichment(1, 'alice');

        $this->assertUnpriced();
    }

    public function testPatchingTheReleaseIdRetiresItsPrice(): void
    {
        $this->priced('music', 'Kind of Blue', 'Vinyl', '101');

        $this->service()->patchDiscogsId(1, 'alice', '102');

        $this->assertUnpriced();
    }

    public function testEnrichmentThatRetitlesAGameRetiresItsPrice(): void
    {
        $this->priced('game', 'sonic', 'Mega Drive', null);

        $this->service()->applyRawgData(1, 'alice', ['title' => 'Sonic the Hedgehog', 'rawgId' => '53']);

        $this->assertUnpriced();
    }

    private function priced(
        string $category,
        string $title,
        string $format,
        ?string $discogsId,
        ?int $year = null,
    ): void {
        $item = new MediaItem();
        $item->setId(1);
        $item->setUserId('alice');
        $item->setCategory($category);
        $item->setTitle($title);
        $item->setArtist('Someone');
        $item->setFormat($format);
        $item->setYear($year);
        $item->setStatus('owned');
        $item->setDiscogsId($discogsId);
        $item->setMarketValue(20.0);
        $item->setMarketValueLoose(12.0);
        $item->setMarketValueNew(40.0);
        $item->setMarketValueCurrency('USD');
        $item->setMarketValueFetchedAt('2026-10-01 12:00:00');
        $this->item = $item;
    }

    private function assertUnpriced(): void
    {
        self::assertNull($this->item->getMarketValue());
        self::assertNull($this->item->getMarketValueLoose());
        self::assertNull($this->item->getMarketValueNew());
        self::assertNull($this->item->getMarketValueCurrency());
        self::assertNull($this->item->getMarketValueFetchedAt());
    }

    private function assertPriced(): void
    {
        self::assertSame(20.0, $this->item->getMarketValue());
        self::assertSame('2026-10-01 12:00:00', $this->item->getMarketValueFetchedAt());
    }

    private function data(
        ?string $title = null,
        ?string $format = null,
        ?string $discogsId = null,
        ?string $notes = null,
        ?int $year = null,
        ?string $category = null,
    ): MediaItemData {
        return new MediaItemData(
            title: $title ?? $this->item->getTitle(),
            artist: $this->item->getArtist(),
            format: $format ?? $this->item->getFormat(),
            year: $year ?? $this->item->getYear(),
            notes: $notes,
            discogsId: $discogsId ?? $this->item->getDiscogsId(),
            category: $category,
        );
    }

    private function service(): MediaService
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findWritableForUser')->willReturnCallback(fn() => $this->item);
        $mapper->method('update')->willReturnArgument(0);

        return new MediaService(
            $mapper,
            $this->createStub(PlaylistItemMapper::class),
            $this->createStub(CrateShareMapper::class),
            $this->createStub(PlaylistMapper::class),
            $this->createStub(IAppDataFactory::class),
            $this->createStub(IDBConnection::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(ActivityService::class),
            $this->createStub(IConfig::class),
        );
    }
}
