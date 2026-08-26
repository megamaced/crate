<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\CrateShare;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\PlaylistService;
use OCA\Crate\Service\ShareService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every share list is read by a person deciding whether to revoke a share, and
 * only the server can turn the stored uid into the name they know that person
 * by. "Shared by me" already resolves one; the per-resource lists behind each
 * item's share dialog have to agree, or the same share reads as a display name
 * in one place and a raw uid in the other.
 */
#[AllowMockObjectsWithoutExpectations]
class ShareDisplayNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function shareListProvider(): array
    {
        return [
            'album'    => ['getSharesForAlbum'],
            'playlist' => ['getSharesForPlaylist'],
            'library'  => ['getSharesForLibrary'],
            'category' => ['getSharesForCategory'],
        ];
    }

    #[DataProvider('shareListProvider')]
    public function testPerResourceShareListsCarryTheRecipientsDisplayName(string $method): void
    {
        $rows = $this->service('Bob Jones')->{$method}('alice', ...$this->argsFor($method));

        self::assertCount(1, $rows);
        self::assertSame('bob', $rows[0]['sharedWithUserId']);
        self::assertSame('Bob Jones', $rows[0]['sharedWithDisplayName']);
    }

    #[DataProvider('shareListProvider')]
    public function testADeletedAccountFallsBackToItsUid(string $method): void
    {
        $rows = $this->service(null)->{$method}('alice', ...$this->argsFor($method));

        self::assertSame('bob', $rows[0]['sharedWithDisplayName']);
    }

    /**
     * Trailing arguments each share-list method takes after the owner.
     *
     * @return list<int|string>
     */
    private function argsFor(string $method): array
    {
        return match ($method) {
            'getSharesForAlbum', 'getSharesForPlaylist' => [42],
            'getSharesForCategory'                      => ['music'],
            default                                     => [],
        };
    }

    /** A service holding one share with 'bob', who has $displayName (or is gone). */
    private function service(?string $displayName): ShareService
    {
        $share = new CrateShare();
        $share->setId(3);
        $share->setOwnerUserId('alice');
        $share->setSharedWithUserId('bob');
        $share->setShareableType(CrateShare::TYPE_ALBUM);
        $share->setShareableId(42);

        $shareMapper = $this->createMock(CrateShareMapper::class);
        $shareMapper->method('findByOwnerAndShareable')->willReturn([$share]);

        $recipient = null;
        if ($displayName !== null) {
            $recipient = $this->createMock(IUser::class);
            $recipient->method('getDisplayName')->willReturn($displayName);
        }
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($recipient);

        return new ShareService(
            $shareMapper,
            $this->createMock(MediaItemMapper::class),
            $this->createMock(PlaylistService::class),
            $userManager,
        );
    }
}
