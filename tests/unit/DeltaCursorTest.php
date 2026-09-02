<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Service\ActivityService;
use OCA\Crate\Service\MediaService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `updated_at` is a DATETIME: whole seconds on every engine Nextcloud
 * supports. A cursor that is only a timestamp therefore cannot separate two
 * rows edited in the same second, and a client deriving its own next cursor
 * from max(updatedAt) silently drops whichever of them the sweep had not yet
 * reached — permanently, because an edit changes no row count and so never
 * trips a count-drift full sweep.
 *
 * The server issues the cursor instead: the `(updated_at, id)` pair of the last
 * row of the page, which resumes exactly where the page stopped.
 */
#[AllowMockObjectsWithoutExpectations]
class DeltaCursorTest extends TestCase
{
    private MediaItemMapper&MockObject $mapper;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(MediaItemMapper::class);
    }

    private function service(): MediaService
    {
        return new MediaService(
            $this->mapper,
            $this->createMock(PlaylistItemMapper::class),
            $this->createMock(CrateShareMapper::class),
            $this->createMock(PlaylistMapper::class),
            $this->createMock(IAppDataFactory::class),
            $this->createMock(IDBConnection::class),
            new NullLogger(),
            $this->createMock(ActivityService::class),
            $this->createMock(IConfig::class),
        );
    }

    private function item(int $id, string $updatedAt): MediaItem
    {
        $item = new MediaItem();
        $item->setId($id);
        $item->setUserId('alice');
        $item->setTitle('T');
        $item->setUpdatedAt($updatedAt);
        return $item;
    }

    public function testDeltaResponseCarriesTheLastRowAsTheNextCursor(): void
    {
        // Two rows stamped the same second. The cursor has to name which of
        // them was the last one handed over.
        $this->mapper->method('findPaginated')->willReturn([
            $this->item(4, '2026-08-01 12:00:00'),
            $this->item(9, '2026-08-01 12:00:00'),
        ]);
        $this->mapper->method('countAll')->willReturn(2);

        $result = $this->service()->findPaginated('alice', null, null, '2026-08-01 11:59:00');

        self::assertSame(
            ['updatedSince' => '2026-08-01 12:00:00', 'updatedSinceId' => 9],
            $result['nextCursor'],
        );
    }

    public function testCursorHalvesAreHandedStraightToTheQuery(): void
    {
        $this->mapper->expects(self::once())
            ->method('findPaginated')
            ->with('alice', null, null, '2026-08-01 12:00:00', 50, 0, 9)
            ->willReturn([]);
        $this->mapper->expects(self::once())
            ->method('countAll')
            ->with('alice', null, null, '2026-08-01 12:00:00', 9)
            ->willReturn(0);

        $this->service()->findPaginated('alice', null, null, '2026-08-01 12:00:00', 50, 0, 9);
    }

    public function testNoCursorIsOfferedWhenThereIsNothingToResumeFrom(): void
    {
        $this->mapper->method('findPaginated')->willReturn([]);
        $this->mapper->method('countAll')->willReturn(0);

        $result = $this->service()->findPaginated('alice', null, null, '2026-08-01 12:00:00');
        self::assertArrayNotHasKey('nextCursor', $result);
    }

    public function testAFullListingIsNotADeltaAndCarriesNoCursor(): void
    {
        $this->mapper->method('findPaginated')->willReturn([$this->item(4, '2026-08-01 12:00:00')]);
        $this->mapper->method('countAll')->willReturn(1);

        $result = $this->service()->findPaginated('alice');
        self::assertArrayNotHasKey('nextCursor', $result);
    }
}
