<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\Playlist;
use OCA\Crate\Db\PlaylistItem;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class PlaylistService
{
    public function __construct(
        private readonly PlaylistMapper $playlistMapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly MediaItemMapper $mediaItemMapper,
        private readonly CrateShareMapper $shareMapper,
        private readonly IDBConnection $db,
    ) {
    }

    // ── Playlists ──────────────────────────────────────────────────────────────

    /**
     * @param int|null $containsItemId If set, each playlist gets a `containsItem`
     *   boolean indicating whether it already contains the given media item.
     * @return array<int, mixed> List of playlists with itemCount, coverId, coverIds, and categories
     */
    public function findAll(string $userId, ?int $containsItemId = null): array
    {
        $playlists = $this->playlistMapper->findAll($userId);

        // Single batched query for all playlists' items, then collect unique media-item IDs.
        $allMediaIds = [];
        $playlistIds = array_map(fn($p) => $p->getId(), $playlists);
        $playlistItems = $this->playlistItemMapper->findByPlaylistIds($playlistIds);
        foreach ($playlistItems as $items) {
            foreach ($items as $item) {
                $allMediaIds[$item->getMediaItemId()] = true;
            }
        }

        // Batch-fetch categories for all referenced media items
        $categoryMap = [];
        if (!empty($allMediaIds)) {
            foreach ($this->mediaItemMapper->findByIds(array_keys($allMediaIds)) as $mi) {
                $categoryMap[$mi->getId()] = $mi->getCategory() ?? 'music';
            }
        }

        // Second pass: build result with coverIds and categories
        $result = [];
        foreach ($playlists as $playlist) {
            $items = $playlistItems[$playlist->getId()];
            $data = $playlist->jsonSerialize();
            $data['itemCount'] = count($items);
            $data['coverId']   = count($items) > 0 ? $items[0]->getMediaItemId() : null;

            $coverIds = [];
            $seen = [];
            $cats = [];
            foreach ($items as $item) {
                $mid = $item->getMediaItemId();
                if (!isset($seen[$mid])) {
                    $seen[$mid] = true;
                    $coverIds[] = $mid;
                    $cat = $categoryMap[$mid] ?? 'music';
                    $cats[$cat] = true;
                }
            }
            $data['coverIds']   = array_slice($coverIds, 0, 4);
            $data['categories'] = array_keys($cats);
            if ($containsItemId !== null) {
                $data['containsItem'] = isset($seen[$containsItemId]);
            }
            $result[] = $data;
        }
        return $result;
    }

    /** @return array<string, mixed> Playlist with full item data */
    public function find(int $id, string $userId): array
    {
        $playlist = $this->playlistMapper->findByUser($id, $userId);
        return $this->hydrateWithItems($playlist);
    }

    /**
     * Find a playlist that has been shared with $viewerUserId.
     * Throws DoesNotExistException if no active share exists.
     */
    public function findForSharedAccess(int $id, string $viewerUserId): array
    {
        if (!$this->shareMapper->isSharedWith($viewerUserId, 'playlist', $id)) {
            throw new DoesNotExistException('Playlist not shared with user');
        }
        $playlist = $this->playlistMapper->findById($id);
        return $this->hydrateWithItems($playlist);
    }

    public function create(string $userId, string $name, ?string $description): array
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $playlist = new Playlist();
        $playlist->setUserId($userId);
        $playlist->setName($name);
        $playlist->setDescription($description);
        $playlist->setCreatedAt($now);
        $playlist->setUpdatedAt($now);
        $saved = $this->playlistMapper->insert($playlist);
        $data = $saved->jsonSerialize();
        $data['itemCount']  = 0;
        $data['coverId']    = null;
        $data['categories'] = [];
        $data['items']      = [];
        return $data;
    }

    /**
     * Resolve a playlist the caller may WRITE — they own it, or hold a
     * read/write playlist share of it. Used for rename and add/remove track.
     * Deleting a playlist stays owner-only (findByUser).
     *
     * @throws DoesNotExistException if the caller neither owns nor has RW access
     */
    private function resolveWritablePlaylist(int $id, string $userId): Playlist
    {
        try {
            return $this->playlistMapper->findByUser($id, $userId);
        } catch (DoesNotExistException $e) {
            if ($this->shareMapper->isWritableSharedWith($userId, 'playlist', $id)) {
                return $this->playlistMapper->findById($id);
            }
            throw $e;
        }
    }

    public function update(int $id, string $userId, string $name, ?string $description): array
    {
        // Owner or a read/write sharee may rename. (Delete stays owner-only.)
        $playlist = $this->resolveWritablePlaylist($id, $userId);
        $playlist->setName($name);
        $playlist->setDescription($description);
        $playlist->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        $this->playlistMapper->update($playlist);
        return $this->hydrateWithItems($playlist);
    }

    public function delete(int $id, string $userId): void
    {
        $playlist = $this->playlistMapper->findByUser($id, $userId);
        $this->db->beginTransaction();
        try {
            $this->playlistItemMapper->deleteByPlaylist($id);
            $this->shareMapper->deleteByShareable('playlist', $id);
            $this->playlistMapper->delete($playlist);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Playlist items ─────────────────────────────────────────────────────────

    public function addItem(int $playlistId, string $userId, int $mediaItemId): array
    {
        // Owner or a read/write sharee may add tracks.
        $playlist = $this->resolveWritablePlaylist($playlistId, $userId);
        // The track must be an item the caller can view (their own, or one
        // shared with them) — not necessarily the playlist owner's.
        $this->mediaItemMapper->findVisibleForUser($mediaItemId, $userId);

        if (!$this->playlistItemMapper->existsInPlaylist($playlistId, $mediaItemId)) {
            $now    = (new \DateTime())->format('Y-m-d H:i:s');
            $maxPos = $this->playlistItemMapper->maxPosition($playlistId);
            $item = new PlaylistItem();
            $item->setPlaylistId($playlistId);
            $item->setMediaItemId($mediaItemId);
            $item->setPosition($maxPos + 1);
            $item->setAddedAt($now);

            $this->db->beginTransaction();
            try {
                $this->playlistItemMapper->insert($item);
                $playlist->setUpdatedAt($now);
                $this->playlistMapper->update($playlist);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        return $this->hydrateWithItems($playlist);
    }

    public function removeItem(int $playlistId, string $userId, int $mediaItemId): array
    {
        // Owner or a read/write sharee may remove tracks.
        $playlist = $this->resolveWritablePlaylist($playlistId, $userId);

        $this->db->beginTransaction();
        try {
            $this->playlistItemMapper->deleteByPlaylistAndItem($playlistId, $mediaItemId);
            $playlist->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
            $this->playlistMapper->update($playlist);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->hydrateWithItems($playlist);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function hydrateWithItems(Playlist $playlist): array
    {
        $pItems = $this->playlistItemMapper->findByPlaylist($playlist->getId());

        // Bulk-fetch all referenced media items in one query, then reorder to
        // match the playlist's position order. Tracks are included regardless of
        // which user owns them: a read/write sharee may add their own items to a
        // shared playlist, and every participant should see the full track list.
        // Only authorised writers (owner or RW sharee) can create these rows.
        $ids = array_map(fn($pi) => $pi->getMediaItemId(), $pItems);
        $byId = [];
        foreach ($this->mediaItemMapper->findByIds($ids) as $mi) {
            $byId[$mi->getId()] = $mi;
        }

        $mediaItems = [];
        foreach ($pItems as $pi) {
            $mi = $byId[$pi->getMediaItemId()] ?? null;
            if ($mi !== null) {
                $mediaItems[] = $mi->jsonSerialize();
            }
        }

        $data = $playlist->jsonSerialize();
        $data['items']     = $mediaItems;
        $data['itemCount'] = count($mediaItems);
        $data['coverId']   = count($pItems) > 0 ? $pItems[0]->getMediaItemId() : null;
        return $data;
    }
}
