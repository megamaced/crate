<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\Playlist;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Listener\UserDeletedListener;
use OCA\Crate\Service\ActivityService;
use OCA\Crate\Service\MediaService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * None of the app's tables reference the accounts table, so deleting a user
 * leaves their rows behind unless something clears them. The share rows matter
 * most: the ones they granted keep a vanished collection readable, and the ones
 * they received transfer wholesale to whoever is next given that UID.
 */
#[AllowMockObjectsWithoutExpectations]
class UserDeletedCleanupTest extends TestCase
{
    public function testPurgeClearsItemsPlaylistsAndSharesOnBothSides(): void
    {
        $item = new MediaItem();
        $item->setId(11);
        $item->setUserId('alice');
        $playlist = new Playlist();
        $playlist->setId(3);
        $playlist->setUserId('alice');

        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('findAll')->willReturn([$item]);
        $mapper->expects(self::once())->method('deleteAllByUser')->with('alice');

        $playlistMapper = $this->createMock(PlaylistMapper::class);
        $playlistMapper->method('findAll')->willReturn([$playlist]);
        $playlistMapper->expects(self::once())->method('deleteAllByUser')->with('alice');

        $playlistItemMapper = $this->createMock(PlaylistItemMapper::class);
        $playlistItemMapper->expects(self::once())->method('deleteByPlaylist')->with(3);
        // The user's items can sit in other people's playlists too.
        $playlistItemMapper->expects(self::once())->method('deleteByMediaItem')->with(11);

        $shareMapper = $this->createMock(CrateShareMapper::class);
        $shareMapper->expects(self::once())->method('deleteAllByOwner')->with('alice');
        $shareMapper->expects(self::once())->method('deleteAllReceivedByUser')->with('alice');

        $db = $this->createMock(IDBConnection::class);
        $db->expects(self::once())->method('beginTransaction');
        $db->expects(self::once())->method('commit');
        $db->expects(self::never())->method('rollBack');

        // Artwork and photo sweeps run against appdata after the commit.
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('getFile')->willThrowException(new NotFoundException('gone'));
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($folder);
        $appDataFactory = $this->createMock(IAppDataFactory::class);
        $appDataFactory->method('get')->willReturn($appData);

        $config = $this->createMock(IConfig::class);
        // A deleted account has no client left to tell about a wipe.
        $config->expects(self::never())->method('setUserValue');

        $service = new MediaService(
            $mapper,
            $playlistItemMapper,
            $shareMapper,
            $playlistMapper,
            $appDataFactory,
            $db,
            $this->createStub(LoggerInterface::class),
            $this->createStub(ActivityService::class),
            $config,
        );

        $service->purgeUser('alice');
    }

    public function testListenerPurgesTheDeletedAccount(): void
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->expects(self::once())->method('purgeUser')->with('alice');

        $listener = new UserDeletedListener($mediaService, $this->createStub(LoggerInterface::class));
        $listener->handle(new UserDeletedEvent($user));
    }

    public function testListenerLogsRatherThanBreakingTheAccountDeletion(): void
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('alice');

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('purgeUser')->willThrowException(new \RuntimeException('db down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $listener = new UserDeletedListener($mediaService, $logger);
        $listener->handle(new UserDeletedEvent($user));
    }
}
