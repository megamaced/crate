<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\Playlist;
use OCA\Crate\Db\PlaylistItem;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Service\PlaylistService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A playlist is the one place where items belonging to different users meet,
 * which makes it the one place a share can be laundered: Alice shares an item
 * read-only with Bob, Bob drops it into a playlist of his own and shares that
 * playlist with Dave. There is no reshare permission, so Dave must not end up
 * holding Alice's item — not in the track list, and not through the artwork and
 * photo endpoints that authorise off the same visibility query.
 */
#[AllowMockObjectsWithoutExpectations]
class PlaylistSharingTest extends TestCase
{
    private PlaylistMapper&MockObject $playlistMapper;
    private PlaylistItemMapper&MockObject $playlistItemMapper;
    private MediaItemMapper&MockObject $mediaItemMapper;
    private CrateShareMapper&MockObject $shareMapper;
    private IDBConnection&MockObject $db;

    protected function setUp(): void
    {
        $this->playlistMapper     = $this->createMock(PlaylistMapper::class);
        $this->playlistItemMapper = $this->createMock(PlaylistItemMapper::class);
        $this->mediaItemMapper    = $this->createMock(MediaItemMapper::class);
        $this->shareMapper        = $this->createMock(CrateShareMapper::class);
        $this->db                 = $this->createMock(IDBConnection::class);
    }

    private function service(): PlaylistService
    {
        return new PlaylistService(
            $this->playlistMapper,
            $this->playlistItemMapper,
            $this->mediaItemMapper,
            $this->shareMapper,
            $this->db,
        );
    }

    private function playlist(int $id, string $userId): Playlist
    {
        $playlist = new Playlist();
        $playlist->setId($id);
        $playlist->setUserId($userId);
        $playlist->setName('Q');
        return $playlist;
    }

    private function item(int $id, string $userId, string $title): MediaItem
    {
        $item = new MediaItem();
        $item->setId($id);
        $item->setUserId($userId);
        $item->setTitle($title);
        $item->setNotes('private note');
        return $item;
    }

    private function playlistItem(int $playlistId, int $mediaItemId): PlaylistItem
    {
        $row = new PlaylistItem();
        $row->setPlaylistId($playlistId);
        $row->setMediaItemId($mediaItemId);
        return $row;
    }

    public function testItemSharedWithTheCallerCannotBeAddedToTheirOwnPlaylist(): void
    {
        // Bob owns playlist 7; item 42 is Alice's, read-shared with Bob.
        $this->playlistMapper->method('findByUser')->willReturn($this->playlist(7, 'bob'));
        $this->mediaItemMapper->expects(self::once())
            ->method('findById')
            ->with(42)
            ->willReturn($this->item(42, 'alice', "Alice's record"));
        $this->playlistItemMapper->expects(self::never())->method('insert');

        $this->expectException(DoesNotExistException::class);
        $this->service()->addItem(7, 'bob', 42);
    }

    public function testCallerMayAddTheirOwnItem(): void
    {
        $this->playlistMapper->method('findByUser')->willReturn($this->playlist(7, 'bob'));
        $this->mediaItemMapper->method('findById')->willReturn($this->item(9, 'bob', "Bob's record"));
        $this->playlistItemMapper->method('existsInPlaylist')->willReturn(false);
        $this->playlistItemMapper->method('maxPosition')->willReturn(-1);
        $this->playlistItemMapper->expects(self::once())->method('insert');
        $this->playlistItemMapper->method('findByPlaylist')
            ->willReturn([$this->playlistItem(7, 9)]);
        $this->mediaItemMapper->method('findByIds')
            ->willReturn([$this->item(9, 'bob', "Bob's record")]);

        $result = $this->service()->addItem(7, 'bob', 9);
        self::assertSame(1, $result['itemCount']);
    }

    public function testWritableShareeMayAddAnItemBelongingToThePlaylistOwner(): void
    {
        // Carol holds a read/write share of Bob's playlist 7.
        $this->playlistMapper->method('findByUser')
            ->willThrowException(new DoesNotExistException('not owner'));
        $this->shareMapper->method('isWritableSharedWith')->willReturn(true);
        $this->playlistMapper->method('findById')->willReturn($this->playlist(7, 'bob'));
        $this->mediaItemMapper->method('findById')->willReturn($this->item(9, 'bob', "Bob's record"));
        $this->playlistItemMapper->method('existsInPlaylist')->willReturn(false);
        $this->playlistItemMapper->method('maxPosition')->willReturn(-1);
        $this->playlistItemMapper->expects(self::once())->method('insert');
        $this->playlistItemMapper->method('findByPlaylist')
            ->willReturn([$this->playlistItem(7, 9)]);
        $this->mediaItemMapper->method('findByIds')
            ->willReturn([$this->item(9, 'bob', "Bob's record")]);

        $result = $this->service()->addItem(7, 'carol', 9);
        self::assertSame(1, $result['itemCount']);
    }

    public function testWritableShareeMayNotAddAnItemOfTheirOwn(): void
    {
        // Carol holds a read/write share of Bob's playlist 7 and tries to
        // contribute one of her own records. Stored, it would be visible to
        // Carol and hidden from Bob by hydrateWithItems() — two itemCounts for
        // one playlist, and a membership row its owner cannot remove.
        $this->playlistMapper->method('findByUser')
            ->willThrowException(new DoesNotExistException('not owner'));
        $this->shareMapper->method('isWritableSharedWith')->willReturn(true);
        $this->playlistMapper->method('findById')->willReturn($this->playlist(7, 'bob'));
        $this->mediaItemMapper->method('findById')->willReturn($this->item(9, 'carol', "Carol's record"));
        $this->playlistItemMapper->expects(self::never())->method('insert');

        $this->expectException(DoesNotExistException::class);
        $this->service()->addItem(7, 'carol', 9);
    }

    public function testSharedPlaylistHidesTracksBelongingToNeitherViewerNorOwner(): void
    {
        // Dave holds a share of Bob's playlist, which lists one of Bob's items,
        // one of Dave's own, and one that is still Alice's.
        $this->shareMapper->method('isSharedWith')->willReturn(true);
        $this->playlistMapper->method('findById')->willReturn($this->playlist(7, 'bob'));
        $this->playlistItemMapper->method('findByPlaylist')->willReturn([
            $this->playlistItem(7, 9),
            $this->playlistItem(7, 42),
            $this->playlistItem(7, 55),
        ]);
        $this->mediaItemMapper->method('findByIds')->willReturn([
            $this->item(9, 'bob', "Bob's record"),
            $this->item(42, 'alice', "Alice's record"),
            $this->item(55, 'dave', "Dave's record"),
        ]);

        $result = $this->service()->findForSharedAccess(7, 'dave');

        $titles = array_map(fn(array $i) => $i['title'], $result['items']);
        self::assertSame(["Bob's record", "Dave's record"], $titles);
        self::assertSame(2, $result['itemCount']);
        // The cover has to be a track this viewer can actually fetch artwork for.
        self::assertSame(9, $result['coverId']);
    }
}
