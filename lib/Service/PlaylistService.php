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
use OCP\DB\Exception as DbException;
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
        return $this->hydrateWithItems($playlist, $userId);
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
        return $this->hydrateWithItems($playlist, $viewerUserId);
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
        return $this->hydrateWithItems($playlist, $userId);
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

    /**
     * @throws DoesNotExistException if the caller may not write the playlist,
     *   or the track is neither theirs nor the playlist owner's
     */
    public function addItem(int $playlistId, string $userId, int $mediaItemId): array
    {
        // Owner or a read/write sharee may add tracks.
        $playlist = $this->resolveWritablePlaylist($playlistId, $userId);
        // The track must belong to the caller or to the playlist owner. Read
        // access is not enough: a sharee could otherwise put an item they can
        // merely see into their own playlist and share that playlist onwards,
        // handing a third party an item its owner never shared with them.
        $item = $this->mediaItemMapper->findById($mediaItemId);
        if ($item->getUserId() !== $userId && $item->getUserId() !== $playlist->getUserId()) {
            throw new DoesNotExistException('Media item not available to this playlist');
        }

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
                // Two concurrent "add to playlist" clicks race past the
                // existence check above; the loser hits crate_pli_unique and
                // has nothing left to do, because the row it wanted is there.
                if (
                    !($e instanceof DbException)
                    || $e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION
                ) {
                    throw $e;
                }
            }
        }

        return $this->hydrateWithItems($playlist, $userId);
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

        return $this->hydrateWithItems($playlist, $userId);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Serialise a playlist together with the tracks $viewerUserId is entitled
     * to see. A track is included when the viewer owns it, or when it belongs
     * to the playlist's owner (whose collection the playlist share exposes).
     * A third contributor's own tracks stay hidden: the full record includes
     * notes, purchase price and barcode, and no share grants a reader access
     * to a stranger's items just because both appear in one playlist.
     *
     * @return array<string, mixed>
     */
    private function hydrateWithItems(Playlist $playlist, string $viewerUserId): array
    {
        $pItems = $this->playlistItemMapper->findByPlaylist($playlist->getId());

        // Bulk-fetch all referenced media items in one query, then reorder to
        // match the playlist's position order.
        $ids = array_map(fn($pi) => $pi->getMediaItemId(), $pItems);
        $byId = [];
        foreach ($this->mediaItemMapper->findByIds($ids) as $mi) {
            $byId[$mi->getId()] = $mi;
        }

        $ownerUserId = $playlist->getUserId();
        $mediaItems  = [];
        foreach ($pItems as $pi) {
            $mi = $byId[$pi->getMediaItemId()] ?? null;
            if ($mi === null) {
                continue;
            }
            if ($mi->getUserId() !== $viewerUserId && $mi->getUserId() !== $ownerUserId) {
                continue;
            }
            $mediaItems[] = $mi->jsonSerialize();
        }

        $data = $playlist->jsonSerialize();
        $data['items']     = $mediaItems;
        $data['itemCount'] = count($mediaItems);
        // The cover comes from the visible tracks: an id the viewer cannot
        // fetch artwork for would only render as a broken image.
        $data['coverId']   = $mediaItems[0]['id'] ?? null;
        return $data;
    }
}
