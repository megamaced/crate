<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\ImportService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "0" is a real title (Low Roar's 2014 album) and a plausible artist, label or
 * note. PHP's empty() treats the string "0" as blank, so validating with it
 * skipped such rows as "missing Artist or Title" and dropped the optional
 * fields, although the write API accepts every one of them.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportZeroValueTest extends TestCase
{
    /** @var list<MediaItem> */
    private array $inserted = [];

    public function testATitleOrArtistOfZeroIsImported(): void
    {
        $result = $this->import([
            ['artist' => 'Low Roar', 'title' => '0', 'format' => 'CD'],
            ['artist' => '0', 'title' => 'Untitled', 'format' => 'Vinyl'],
        ]);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['skipped']);
        self::assertSame([], $result['errors']);
        self::assertSame('0', $this->inserted[0]->getTitle());
        self::assertSame('0', $this->inserted[1]->getArtist());
    }

    public function testOptionalFieldsOfZeroAreKept(): void
    {
        $this->import([[
            'artist' => 'Low Roar', 'title' => '0', 'format' => 'CD',
            'notes' => '0', 'label' => '0', 'barcode' => '0',
        ]]);

        self::assertSame('0', $this->inserted[0]->getNotes());
        self::assertSame('0', $this->inserted[0]->getLabel());
        self::assertSame('0', $this->inserted[0]->getBarcode());
    }

    public function testBlankRequiredFieldsAreStillRejected(): void
    {
        $result = $this->import([
            ['artist' => '', 'title' => '0', 'format' => 'CD'],
            ['artist' => 'Low Roar', 'title' => null, 'format' => 'CD'],
            ['artist' => 'Low Roar', 'title' => '0', 'format' => ''],
        ]);

        self::assertSame(0, $result['created']);
        self::assertSame(
            [
                'Row 2: missing Artist or Title — skipped',
                'Row 3: missing Artist or Title — skipped',
                'Row 4: missing Format — skipped',
            ],
            array_map('strval', $result['errors']),
        );
    }

    /**
     * @param list<array<string, string|null>> $rows
     * @return array{created: int, duplicates: int, skipped: int, errors: string[], itemIds: int[]}
     */
    private function import(array $rows): array
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->willReturn([]);
        $mapper->method('insert')->willReturnCallback(function (MediaItem $item): MediaItem {
            $item->setId(count($this->inserted) + 1);
            $this->inserted[] = $item;
            return $item;
        });

        $service = new ImportService(
            $mapper,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IDBConnection::class),
        );

        return $service->import($rows, 'alice');
    }
}
