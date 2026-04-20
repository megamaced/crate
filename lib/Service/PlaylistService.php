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

class PlaylistService
{
    public function __construct(
        private readonly PlaylistMapper $playlistMapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly MediaItemMapper $mediaItemMapper,
        private readonly CrateShareMapper $shareMapper,
    ) {
    }

    // ── Playlists ──────────────────────────────────────────────────────────────

    /** @return array<int, mixed> List of playlists with itemCount, coverId, and coverIds */
    public function findAll(string $userId): array
    {
        $playlists = $this->playlistMapper->findAll($userId);
        $result = [];
        foreach ($playlists as $playlist) {
            $items = $this->playlistItemMapper->findByPlaylist($playlist->getId());
            $data = $playlist->jsonSerialize();
            $data['itemCount'] = count($items);
            $data['coverId']   = count($items) > 0 ? $items[0]->getMediaItemId() : null;
            // Return up to 4 unique media-item IDs that have artwork, for grid cover
            $coverIds = [];
            $seen = [];
            foreach ($items as $item) {
                $mid = $item->getMediaItemId();
                if (isset($seen[$mid])) {
                    continue;
                }
                $seen[$mid] = true;
                $coverIds[] = $mid;
                if (count($coverIds) >= 4) {
                    break;
                }
            }
            $data['coverIds'] = $coverIds;
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
        return $this->hydrateWithItems($playlist, $playlist->getUserId());
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
        $data['itemCount'] = 0;
        $data['coverId']   = null;
        $data['items']     = [];
        return $data;
    }

    public function update(int $id, string $userId, string $name, ?string $description): array
    {
        $playlist = $this->playlistMapper->findByUser($id, $userId);
        $playlist->setName($name);
        $playlist->setDescription($description);
        $playlist->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        $this->playlistMapper->update($playlist);
        return $this->hydrateWithItems($playlist);
    }

    public function delete(int $id, string $userId): void
    {
        $playlist = $this->playlistMapper->findByUser($id, $userId);
        $this->playlistItemMapper->deleteByPlaylist($id);
        $this->shareMapper->deleteByShareable('playlist', $id);
        $this->playlistMapper->delete($playlist);
    }

    // ── Playlist items ─────────────────────────────────────────────────────────

    public function addItem(int $playlistId, string $userId, int $mediaItemId): array
    {
        $playlist = $this->playlistMapper->findByUser($playlistId, $userId);
        // Verify the media item belongs to this user
        $this->mediaItemMapper->findByUser($mediaItemId, $userId);

        if (!$this->playlistItemMapper->existsInPlaylist($playlistId, $mediaItemId)) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $maxPos = $this->playlistItemMapper->maxPosition($playlistId);
            $item = new PlaylistItem();
            $item->setPlaylistId($playlistId);
            $item->setMediaItemId($mediaItemId);
            $item->setPosition($maxPos + 1);
            $item->setAddedAt($now);
            $this->playlistItemMapper->insert($item);

            $playlist->setUpdatedAt($now);
            $this->playlistMapper->update($playlist);
        }

        return $this->hydrateWithItems($playlist);
    }

    public function removeItem(int $playlistId, string $userId, int $mediaItemId): array
    {
        $playlist = $this->playlistMapper->findByUser($playlistId, $userId);
        $this->playlistItemMapper->deleteByPlaylistAndItem($playlistId, $mediaItemId);
        $playlist->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        $this->playlistMapper->update($playlist);
        return $this->hydrateWithItems($playlist);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function hydrateWithItems(Playlist $playlist, ?string $ownerUserId = null): array
    {
        $uid   = $ownerUserId ?? $playlist->getUserId();
        $pItems = $this->playlistItemMapper->findByPlaylist($playlist->getId());
        $mediaItems = [];
        foreach ($pItems as $pi) {
            try {
                $mediaItem = $this->mediaItemMapper->findByUser($pi->getMediaItemId(), $uid);
                $mediaItems[] = $mediaItem->jsonSerialize();
            } catch (DoesNotExistException) {
                // Item was deleted — skip silently
            }
        }
        $data = $playlist->jsonSerialize();
        $data['items']     = $mediaItems;
        $data['itemCount'] = count($mediaItems);
        $data['coverId']   = count($pItems) > 0 ? $pItems[0]->getMediaItemId() : null;
        return $data;
    }
}
