<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Dto\MediaItemData;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;

class MediaService
{
    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly CrateShareMapper $shareMapper,
        private readonly IAppDataFactory $appDataFactory,
    ) {
    }

    /** @return MediaItem[] */
    public function findAll(string $userId, ?string $category = null): array
    {
        return $this->mapper->findAll($userId, $category);
    }

    /**
     * Paginated list for the REST API.
     * Returns ['items' => MediaItem[], 'total' => int].
     */
    public function findPaginated(
        string $userId,
        ?string $status = null,
        ?string $category = null,
        ?string $updatedSince = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $limit = max(1, min(200, $limit));
        return [
            'items' => $this->mapper->findPaginated($userId, $status, $category, $updatedSince, $limit, $offset),
            'total' => $this->mapper->countAll($userId, $status, $category, $updatedSince),
        ];
    }

    public function find(int $id, string $userId): MediaItem
    {
        return $this->mapper->findByUser($id, $userId);
    }

    public function create(
        string $userId,
        MediaItemData $data,
    ): MediaItem {
        $item = new MediaItem();
        $item->setUserId($userId);
        $item->setTitle($data->title);
        $item->setArtist($data->artist);
        $item->setFormat($data->format);
        $item->setYear($data->year);
        $item->setBarcode($data->barcode);
        $item->setNotes($data->notes);
        $item->setStatus($data->status);
        $item->setDiscogsId($data->discogsId);
        $item->setArtworkPath($data->artworkPath);
        $item->setLabel($data->label);
        $item->setCountry($data->country);
        $item->setCategory($data->category);
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $item->setCreatedAt($now);
        $item->setUpdatedAt($now);
        return $this->mapper->insert($item);
    }

    public function update(
        int $id,
        string $userId,
        MediaItemData $data,
    ): MediaItem {
        $item = $this->mapper->findByUser($id, $userId);
        $item->setTitle($data->title);
        $item->setArtist($data->artist);
        $item->setFormat($data->format);
        $item->setYear($data->year);
        $item->setBarcode($data->barcode);
        $item->setNotes($data->notes);
        $item->setStatus($data->status);
        $item->setDiscogsId($data->discogsId);
        // Only overwrite artwork / label / country if the caller explicitly provides a value,
        // so that enriched data is not wiped when the user edits notes or other basic fields.
        // null = don't change; empty string = clear to null; non-empty = set value.
        if ($data->artworkPath !== null) {
            $item->setArtworkPath($data->artworkPath !== '' ? $data->artworkPath : null);
        }
        if ($data->label !== null) {
            $item->setLabel($data->label !== '' ? $data->label : null);
        }
        if ($data->country !== null) {
            $item->setCountry($data->country !== '' ? $data->country : null);
        }
        $item->setCategory($data->category);
        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    public function delete(int $id, string $userId): void
    {
        $item = $this->mapper->findByUser($id, $userId);

        // Clean up related data before deleting the item
        $this->playlistItemMapper->deleteByMediaItem($id);
        $this->shareMapper->deleteByShareable('album', $id);
        $this->deleteArtworkFiles($id);

        $this->mapper->delete($item);
    }

    /** Remove cached/uploaded artwork files for a single item. */
    private function deleteArtworkFiles(int $itemId): void
    {
        try {
            $folder = $this->appDataFactory->get('crate')->getFolder('artwork');
            foreach (['.jpg', '.png', '.webp', '.gif'] as $ext) {
                try {
                    $folder->getFile('artwork_' . $itemId . $ext)->delete();
                } catch (NotFoundException) {
                }
            }
        } catch (NotFoundException) {
        }
    }

    public function deleteAll(string $userId): void
    {
        $this->mapper->deleteAllByUser($userId);
    }

    /**
     * Delete all media items for a user, including related data cleanup.
     * Handles artwork files, playlist-item references, and album shares.
     */
    public function deleteAllForUser(string $userId): void
    {
        $items = $this->findAll($userId);
        foreach ($items as $item) {
            $this->playlistItemMapper->deleteByMediaItem($item->getId());
            $this->shareMapper->deleteByShareable('album', $item->getId());
            $this->deleteArtworkFiles($item->getId());
        }
        $this->mapper->deleteAllByUser($userId);
    }

    /**
     * Persist a Discogs release ID onto an item without changing anything else.
     */
    public function patchDiscogsId(int $id, string $userId, string $discogsId): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);
        $item->setDiscogsId($discogsId);
        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    /**
     * Strip all Discogs-sourced enrichment fields from an item, preserving the
     * user-entered fields (title, artist, format, year, notes, status, artwork).
     * The discogsId is also cleared so the item is treated as unenriched.
     */
    public function stripEnrichment(int $id, string $userId): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        // Restore pre-enrichment values if a snapshot was taken, then clear it.
        if ($item->getOriginalTitle() !== null) {
            $item->setTitle($item->getOriginalTitle());
            $item->setArtist($item->getOriginalArtist());
            $item->setYear($item->getOriginalYear());
            $item->setArtworkPath($item->getOriginalArtworkPath());
            $item->setOriginalTitle(null);
            $item->setOriginalArtist(null);
            $item->setOriginalYear(null);
            $item->setOriginalArtworkPath(null);
        }

        $item->setGenres(null);
        $item->setTracklist(null);
        $item->setPressingNotes(null);
        $item->setLabel(null);
        $item->setCountry(null);
        $item->setDiscogsArtistId(null);
        $item->setArtistBio(null);
        $item->setArtistMembers(null);
        $item->setDiscogsId(null);
        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    /**
     * Enrich an existing media item with full release data from Discogs.
     *
     * $release comes from DiscogsService::getRelease().
     * $artist  comes from DiscogsService::getArtist() (may be empty).
     *
     * Only non-null values in the Discogs responses overwrite existing item data,
     * so any field the user has set manually is only replaced if Discogs provides
     * a value for it.
     *
     * @param array<string, mixed> $release
     * @param array<string, mixed> $artist
     */
    public function applyReleaseData(int $id, string $userId, array $release, array $artist = []): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        // Snapshot the pre-enrichment values on first enrichment only, so that
        // "Remove Discogs data" can restore exactly what the user originally entered.
        if ($item->getOriginalTitle() === null) {
            $item->setOriginalTitle($item->getTitle());
            $item->setOriginalArtist($item->getArtist());
            $item->setOriginalYear($item->getYear());
            $item->setOriginalArtworkPath($item->getArtworkPath());
        }

        if (!empty($release['title'])) {
            $item->setTitle($release['title']);
        }
        if (!empty($release['artist'])) {
            $item->setArtist($release['artist']);
        }
        // Format is not overwritten — the user's stored format is their explicit record
        // of which pressing/format they own, and should not be changed by enrichment.
        if (isset($release['year'])) {
            $item->setYear($release['year']);
        }
        if (!empty($release['label'])) {
            $item->setLabel($release['label']);
        }
        if (!empty($release['country'])) {
            $item->setCountry($release['country']);
        }
        if (!empty($release['genres'])) {
            $item->setGenres($release['genres']);
        }
        if (isset($release['tracklist']) && is_array($release['tracklist'])) {
            $item->setTracklist(json_encode($release['tracklist']));
        }
        if (!empty($release['pressingNotes'])) {
            $item->setPressingNotes($release['pressingNotes']);
        }
        if (!empty($release['discogsArtistId'])) {
            $item->setDiscogsArtistId($release['discogsArtistId']);
        }
        // Use full-size artwork URL (ArtworkController will cache it lazily)
        if (!empty($release['artworkUrl'])) {
            $item->setArtworkPath($release['artworkUrl']);
        }

        // Artist fields
        if (!empty($artist['bio'])) {
            $item->setArtistBio($artist['bio']);
        }
        if (isset($artist['members']) && is_array($artist['members'])) {
            $item->setArtistMembers(json_encode($artist['members']));
        }

        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));

        return $this->mapper->update($item);
    }
}
