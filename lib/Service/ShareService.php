<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\CrateShare;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

class ShareService
{
    public function __construct(
        private readonly CrateShareMapper $shareMapper,
        private readonly MediaItemMapper $mediaItemMapper,
        private readonly PlaylistService $playlistService,
        private readonly IUserManager $userManager,
    ) {
    }

    // ── Share creation ─────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if already shared or item not found
     */
    public function shareAlbum(string $ownerUserId, int $mediaItemId, string $sharedWithUserId): array
    {
        $this->validateTargetUser($sharedWithUserId, $ownerUserId);

        // Verify item belongs to owner
        $this->mediaItemMapper->findByUser($mediaItemId, $ownerUserId);

        if ($this->shareMapper->alreadyShared($ownerUserId, $sharedWithUserId, 'album', $mediaItemId)) {
            throw new \InvalidArgumentException('Already shared with this user.');
        }

        $share = $this->createShare($ownerUserId, $sharedWithUserId, 'album', $mediaItemId);
        return $share->jsonSerialize();
    }

    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if already shared or playlist not found
     */
    public function sharePlaylist(string $ownerUserId, int $playlistId, string $sharedWithUserId): array
    {
        $this->validateTargetUser($sharedWithUserId, $ownerUserId);

        // Verify playlist belongs to owner (will throw DoesNotExistException if not)
        $this->playlistService->find($playlistId, $ownerUserId);

        if ($this->shareMapper->alreadyShared($ownerUserId, $sharedWithUserId, 'playlist', $playlistId)) {
            throw new \InvalidArgumentException('Already shared with this user.');
        }

        $share = $this->createShare($ownerUserId, $sharedWithUserId, 'playlist', $playlistId);
        return $share->jsonSerialize();
    }

    // ── Retrieval ──────────────────────────────────────────────────────────────

    /**
     * Returns all items/playlists shared with $userId.
     *
     * @return array{albums: list<array<string,mixed>>, playlists: list<array<string,mixed>>}
     */
    public function getSharedWithMe(string $userId): array
    {
        $shares = $this->shareMapper->findSharedWithUser($userId);
        $albums    = [];
        $playlists = [];

        // Split share list by type and bulk-load album items in a single query.
        $albumShares    = [];
        $playlistShares = [];
        $albumIds       = [];
        foreach ($shares as $share) {
            if ($share->getShareableType() === 'album') {
                $albumShares[] = $share;
                $albumIds[]    = $share->getShareableId();
            } elseif ($share->getShareableType() === 'playlist') {
                $playlistShares[] = $share;
            }
        }

        if (!empty($albumIds)) {
            $items = $this->mediaItemMapper->findByIds($albumIds);
            $itemsById = [];
            foreach ($items as $item) {
                $itemsById[$item->getId()] = $item;
            }
            foreach ($albumShares as $share) {
                $item = $itemsById[$share->getShareableId()] ?? null;
                if ($item === null) {
                    continue; // Shared item was deleted
                }
                $data = $item->jsonSerialize();
                $data['shareId']      = $share->getId();
                $data['sharedByUser'] = $share->getOwnerUserId();
                $albums[] = $data;
            }
        }

        foreach ($playlistShares as $share) {
            try {
                $data = $this->playlistService->findForSharedAccess($share->getShareableId(), $userId);
                $data['shareId']      = $share->getId();
                $data['sharedByUser'] = $share->getOwnerUserId();
                $playlists[] = $data;
            } catch (DoesNotExistException) {
                // Shared playlist was deleted or revoked — skip
            }
        }

        return ['albums' => $albums, 'playlists' => $playlists];
    }

    /**
     * Returns who the owner has shared this album with.
     *
     * @return list<array<string,mixed>>
     */
    public function getSharesForAlbum(string $ownerUserId, int $mediaItemId): array
    {
        return array_map(
            fn(CrateShare $s) => $s->jsonSerialize(),
            $this->shareMapper->findByOwnerAndShareable($ownerUserId, 'album', $mediaItemId),
        );
    }

    /**
     * Returns who the owner has shared this playlist with.
     *
     * @return list<array<string,mixed>>
     */
    public function getSharesForPlaylist(string $ownerUserId, int $playlistId): array
    {
        return array_map(
            fn(CrateShare $s) => $s->jsonSerialize(),
            $this->shareMapper->findByOwnerAndShareable($ownerUserId, 'playlist', $playlistId),
        );
    }

    // ── Deletion ───────────────────────────────────────────────────────────────

    /** Remove a share. Only the owner can call this. */
    public function unshare(int $shareId, string $ownerUserId): void
    {
        $share = $this->shareMapper->findByIdAndOwner($shareId, $ownerUserId);
        $this->shareMapper->delete($share);
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function createShare(
        string $ownerUserId,
        string $sharedWithUserId,
        string $type,
        int $shareableId,
    ): CrateShare {
        $share = new CrateShare();
        $share->setOwnerUserId($ownerUserId);
        $share->setSharedWithUserId($sharedWithUserId);
        $share->setShareableType($type);
        $share->setShareableId($shareableId);
        $share->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->shareMapper->insert($share);
    }

    /** Verify the target user exists and is not the owner. */
    private function validateTargetUser(string $targetUserId, string $ownerUserId): void
    {
        if ($targetUserId === $ownerUserId) {
            throw new \InvalidArgumentException('Cannot share with yourself.');
        }
        if ($this->userManager->get($targetUserId) === null) {
            throw new \InvalidArgumentException('User does not exist.');
        }
    }
}
