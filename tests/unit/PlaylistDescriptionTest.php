<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\PlaylistController;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\Playlist;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Service\PlaylistService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * A playlist rename is a whole-resource PUT, and the clients that only edit
 * the name send just that. Writing the description on every update turns each
 * of those renames into a silent clear of text the user typed somewhere else,
 * so an absent description has to be told apart from one sent as empty — which
 * is still a deliberate clear.
 */
#[AllowMockObjectsWithoutExpectations]
class PlaylistDescriptionTest extends TestCase
{
    public function testRenameWithoutADescriptionKeepsTheStoredOne(): void
    {
        $data = $this->controller('Liner notes')->update(7, 'Late night')->getData();

        self::assertSame('Late night', $data['name']);
        self::assertSame('Liner notes', $data['description']);
    }

    public function testEmptyDescriptionClearsTheStoredOne(): void
    {
        $data = $this->controller('Liner notes')->update(7, 'Late night', '')->getData();

        self::assertSame('', $data['description']);
    }

    public function testSuppliedDescriptionReplacesTheStoredOne(): void
    {
        $data = $this->controller('Liner notes')->update(7, 'Late night', 'Side B only')->getData();

        self::assertSame('Side B only', $data['description']);
    }

    /** A controller over a real service, backed by a playlist with $description. */
    private function controller(?string $description): PlaylistController
    {
        $playlist = new Playlist();
        $playlist->setId(7);
        $playlist->setUserId('alice');
        $playlist->setName('Old name');
        $playlist->setDescription($description);

        $playlistMapper = $this->createMock(PlaylistMapper::class);
        $playlistMapper->method('findByUser')->willReturn($playlist);
        $playlistMapper->method('update')->willReturn($playlist);

        $itemMapper = $this->createMock(PlaylistItemMapper::class);
        $itemMapper->method('findByPlaylist')->willReturn([]);

        $mediaItemMapper = $this->createMock(MediaItemMapper::class);
        $mediaItemMapper->method('findByIds')->willReturn([]);

        $service = new PlaylistService(
            $playlistMapper,
            $itemMapper,
            $mediaItemMapper,
            $this->createMock(CrateShareMapper::class),
            $this->createMock(IDBConnection::class),
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new PlaylistController(
            'crate',
            $this->createMock(IRequest::class),
            $service,
            $session,
        );
    }
}
