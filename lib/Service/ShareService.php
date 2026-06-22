<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
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

        if ($this->shareMapper->alreadyShared($ownerUserId, $sharedWithUserId, CrateShare::TYPE_ALBUM, $mediaItemId)) {
            throw new \InvalidArgumentException('Already shared with this user.');
        }

        $share = $this->createShare($ownerUserId, $sharedWithUserId, CrateShare::TYPE_ALBUM, $mediaItemId);
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

        if ($this->shareMapper->alreadyShared($ownerUserId, $sharedWithUserId, CrateShare::TYPE_PLAYLIST, $playlistId)) {
            throw new \InvalidArgumentException('Already shared with this user.');
        }

        $share = $this->createShare($ownerUserId, $sharedWithUserId, CrateShare::TYPE_PLAYLIST, $playlistId);
        return $share->jsonSerialize();
    }

    /**
     * Share the whole library with another user. Read-only.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if already shared
     */
    public function shareLibrary(string $ownerUserId, string $sharedWithUserId): array
    {
        $this->validateTargetUser($sharedWithUserId, $ownerUserId);

        if ($this->shareMapper->alreadyShared($ownerUserId, $sharedWithUserId, CrateShare::TYPE_LIBRARY, 0)) {
            throw new \InvalidArgumentException('Library already shared with this user.');
        }

        $share = $this->createShare($ownerUserId, $sharedWithUserId, CrateShare::TYPE_LIBRARY, 0);
        return $share->jsonSerialize();
    }

    /**
     * Share a single category with another user. Read-only.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException if already shared or category invalid
     */
    public function shareCategory(string $ownerUserId, string $category, string $sharedWithUserId): array
    {
        $this->validateCategory($category);
        $this->validateTargetUser($sharedWithUserId, $ownerUserId);

        if ($this->shareMapper->alreadyShared($ownerUserId, $sharedWithUserId, CrateShare::TYPE_CATEGORY, 0, $category)) {
            throw new \InvalidArgumentException('Category already shared with this user.');
        }

        $share = $this->createShare($ownerUserId, $sharedWithUserId, CrateShare::TYPE_CATEGORY, 0, $category);
        return $share->jsonSerialize();
    }

    // ── Retrieval ──────────────────────────────────────────────────────────────

    /**
     * Returns all content shared with $userId — albums, playlists, libraries
     * (one per owner) and categories (per category per owner).
     *
     * `libraries` and `categories` resolve to the owner's matching items at
     * read time. There is no caching layer; if a sharer adds an item it
     * becomes visible to the sharee on their next refresh.
     *
     * @return array{
     *     albums: list<array<string,mixed>>,
     *     playlists: list<array<string,mixed>>,
     *     libraries: list<array<string,mixed>>,
     *     categories: list<array<string,mixed>>
     * }
     */
    public function getSharedWithMe(string $userId): array
    {
        $shares = $this->shareMapper->findSharedWithUser($userId);

        $albumShares    = [];
        $albumIds       = [];
        $playlistShares = [];
        $libraryShares  = [];
        $categoryShares = [];

        foreach ($shares as $share) {
            switch ($share->getShareableType()) {
                case CrateShare::TYPE_ALBUM:
                    $albumShares[] = $share;
                    $albumIds[]    = $share->getShareableId();
                    break;
                case CrateShare::TYPE_PLAYLIST:
                    $playlistShares[] = $share;
                    break;
                case CrateShare::TYPE_LIBRARY:
                    $libraryShares[] = $share;
                    break;
                case CrateShare::TYPE_CATEGORY:
                    $categoryShares[] = $share;
                    break;
            }
        }

        return [
            'albums'     => $this->resolveAlbumShares($albumShares, $albumIds),
            'playlists'  => $this->resolvePlaylistShares($playlistShares, $userId),
            'libraries'  => $this->resolveLibraryShares($libraryShares),
            'categories' => $this->resolveCategoryShares($categoryShares),
        ];
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
            $this->shareMapper->findByOwnerAndShareable($ownerUserId, CrateShare::TYPE_ALBUM, $mediaItemId),
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
            $this->shareMapper->findByOwnerAndShareable($ownerUserId, CrateShare::TYPE_PLAYLIST, $playlistId),
        );
    }

    /**
     * Returns who the owner has shared their whole library with.
     *
     * @return list<array<string,mixed>>
     */
    public function getSharesForLibrary(string $ownerUserId): array
    {
        return array_map(
            fn(CrateShare $s) => $s->jsonSerialize(),
            $this->shareMapper->findByOwnerAndShareable($ownerUserId, CrateShare::TYPE_LIBRARY, 0),
        );
    }

    /**
     * Returns who the owner has shared a specific category with.
     *
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException if category invalid
     */
    public function getSharesForCategory(string $ownerUserId, string $category): array
    {
        $this->validateCategory($category);
        return array_map(
            fn(CrateShare $s) => $s->jsonSerialize(),
            $this->shareMapper->findByOwnerAndShareable($ownerUserId, CrateShare::TYPE_CATEGORY, 0, $category),
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
        string $shareableCategory = '',
    ): CrateShare {
        $share = new CrateShare();
        $share->setOwnerUserId($ownerUserId);
        $share->setSharedWithUserId($sharedWithUserId);
        $share->setShareableType($type);
        $share->setShareableId($shareableId);
        $share->setShareableCategory($shareableCategory);
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

    /** Category-share validation: must be one of the five known category keys. */
    private function validateCategory(string $category): void
    {
        if (!in_array($category, CrateCategories::ALL, true)) {
            throw new \InvalidArgumentException('Unknown category.');
        }
    }

    /**
     * @param CrateShare[] $shares
     * @param int[] $albumIds
     * @return list<array<string,mixed>>
     */
    private function resolveAlbumShares(array $shares, array $albumIds): array
    {
        if (empty($albumIds)) {
            return [];
        }
        $items = $this->mediaItemMapper->findByIds($albumIds);
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[$item->getId()] = $item;
        }
        $out = [];
        foreach ($shares as $share) {
            $item = $itemsById[$share->getShareableId()] ?? null;
            if ($item === null) {
                continue; // Shared item was deleted
            }
            $data = $item->jsonSerialize();
            $data['shareId']      = $share->getId();
            $data['sharedByUser'] = $share->getOwnerUserId();
            $out[] = $data;
        }
        return $out;
    }

    /**
     * @param CrateShare[] $shares
     * @return list<array<string,mixed>>
     */
    private function resolvePlaylistShares(array $shares, string $viewerUserId): array
    {
        $out = [];
        foreach ($shares as $share) {
            try {
                $data = $this->playlistService->findForSharedAccess($share->getShareableId(), $viewerUserId);
                $data['shareId']      = $share->getId();
                $data['sharedByUser'] = $share->getOwnerUserId();
                $out[] = $data;
            } catch (DoesNotExistException) {
                // Shared playlist was deleted or revoked — skip
            }
        }
        return $out;
    }

    /**
     * Library shares: each row resolves to the owner's full collection at
     * read time. We return the share envelope + the items so the frontend
     * can render groupings without further round-trips.
     *
     * @param CrateShare[] $shares
     * @return list<array<string,mixed>>
     */
    private function resolveLibraryShares(array $shares): array
    {
        $out = [];
        foreach ($shares as $share) {
            $items = $this->mediaItemMapper->findAll($share->getOwnerUserId());
            $out[] = [
                'shareId'      => $share->getId(),
                'sharedByUser' => $share->getOwnerUserId(),
                'createdAt'    => $share->getCreatedAt(),
                'items'        => array_map(fn($i) => $i->jsonSerialize(), $items),
            ];
        }
        return $out;
    }

    /**
     * @param CrateShare[] $shares
     * @return list<array<string,mixed>>
     */
    private function resolveCategoryShares(array $shares): array
    {
        $out = [];
        foreach ($shares as $share) {
            $items = $this->mediaItemMapper->findAll($share->getOwnerUserId(), $share->getShareableCategory());
            $out[] = [
                'shareId'      => $share->getId(),
                'sharedByUser' => $share->getOwnerUserId(),
                'category'     => $share->getShareableCategory(),
                'createdAt'    => $share->getCreatedAt(),
                'items'        => array_map(fn($i) => $i->jsonSerialize(), $items),
            ];
        }
        return $out;
    }
}
