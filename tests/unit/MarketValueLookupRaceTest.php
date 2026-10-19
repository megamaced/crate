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
use OCA\Crate\Service\MarketValueService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\PriceChartingService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A market lookup takes seconds, and the item can be edited while it is out.
 * The edit retires the old release's price; the lookup must not then write it
 * back onto the new release.
 *
 * The mapper here behaves like the database: every read is a fresh copy of the
 * stored row, and update() writes only the fields that copy marked as changed —
 * which is exactly how a stale copy used to leave a price standing.
 */
#[AllowMockObjectsWithoutExpectations]
class MarketValueLookupRaceTest extends TestCase
{
    private MediaItem $row;

    /** @var (\Closure(): void)|null run while the lookup's request is out */
    private ?\Closure $duringLookup = null;

    protected function setUp(): void
    {
        $row = new MediaItem();
        $row->setId(1);
        $row->setUserId('alice');
        $row->setCategory('music');
        $row->setTitle('Kind of Blue');
        $row->setArtist('Miles Davis');
        $row->setFormat('Vinyl');
        $row->setStatus('owned');
        $row->setDiscogsId('101');
        $row->resetUpdatedFields();
        $this->row = $row;
    }

    public function testAnUnchangedItemIsPriced(): void
    {
        $stored = $this->markets()->fetchAndStore(1, 'alice', 'GBP');

        self::assertSame(99.0, $this->row->getMarketValue());
        self::assertSame(99.0, $stored->getMarketValue());
    }

    public function testALookupForTheOldReleaseIsNotStoredOnTheNewOne(): void
    {
        $this->duringLookup = function (): void {
            $this->media()->update(1, 'alice', new MediaItemData(
                title: 'Kind of Blue',
                artist: 'Miles Davis',
                format: 'Vinyl',
                discogsId: '102',
            ));
        };

        $returned = $this->markets()->fetchAndStore(1, 'alice', 'GBP');

        self::assertSame('102', $this->row->getDiscogsId());
        self::assertNull($this->row->getMarketValue());
        self::assertNull($this->row->getMarketValueFetchedAt());
        // The caller is told what the item is now, not what was looked up.
        self::assertSame('102', $returned->getDiscogsId());
        self::assertNull($returned->getMarketValue());
    }

    public function testAnEditAfterTheLookupStoredRetiresItEvenFromAStaleCopy(): void
    {
        // The edit reads the row before the lookup stores its price and writes
        // after: its copy holds no price, so only a retirement that writes the
        // nulls regardless clears the one stored in between.
        $editorCopy = $this->read();
        $this->markets()->fetchAndStore(1, 'alice', 'GBP');
        self::assertSame(99.0, $this->row->getMarketValue());

        $editorCopy->setDiscogsId('102');
        $editorCopy->retireMarketValue();
        $this->write($editorCopy);

        self::assertNull($this->row->getMarketValue());
        self::assertNull($this->row->getMarketValueCurrency());
    }

    private function read(): MediaItem
    {
        $copy = clone $this->row;
        $copy->resetUpdatedFields();
        return $copy;
    }

    private function write(MediaItem $copy): MediaItem
    {
        foreach (array_keys($copy->getUpdatedFields()) as $field) {
            $value = $copy->{'get' . ucfirst($field)}();
            $this->row->{'set' . ucfirst($field)}($value);
        }
        $this->row->resetUpdatedFields();
        return $this->read();
    }

    private function mapper(): MediaItemMapper
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findWritableForUser')->willReturnCallback(fn() => $this->read());
        $mapper->method('update')->willReturnCallback(fn(MediaItem $copy) => $this->write($copy));
        return $mapper;
    }

    private function markets(): MarketValueService
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function (): IResponse {
            if ($this->duringLookup !== null) {
                ($this->duringLookup)();
            }
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn(json_encode([
                'lowest_price' => ['value' => 99.0, 'currency' => 'GBP'],
            ]));
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);

        return new MarketValueService(
            $this->mapper(),
            $clients,
            $this->createStub(ICredentialsManager::class),
            $this->createStub(PriceChartingService::class),
            $this->createStub(IDBConnection::class),
        );
    }

    private function media(): MediaService
    {
        return new MediaService(
            $this->mapper(),
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
