<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;

class MediaService
{
    public function __construct(private readonly MediaItemMapper $mapper)
    {
    }

    /** @return MediaItem[] */
    public function findAll(string $userId): array
    {
        return $this->mapper->findAll($userId);
    }

    public function find(int $id, string $userId): MediaItem
    {
        return $this->mapper->findByUser($id, $userId);
    }

    public function create(
        string $userId,
        string $title,
        string $artist,
        string $format,
        ?int $year,
        ?string $barcode,
        ?string $notes,
        string $status,
        ?string $discogsId = null,
        ?string $artworkPath = null,
        ?string $label = null,
        ?string $country = null,
    ): MediaItem {
        $item = new MediaItem();
        $item->setUserId($userId);
        $item->setTitle($title);
        $item->setArtist($artist);
        $item->setFormat($format);
        $item->setYear($year);
        $item->setBarcode($barcode);
        $item->setNotes($notes);
        $item->setStatus($status);
        $item->setDiscogsId($discogsId);
        $item->setArtworkPath($artworkPath);
        $item->setLabel($label);
        $item->setCountry($country);
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $item->setCreatedAt($now);
        $item->setUpdatedAt($now);
        return $this->mapper->insert($item);
    }

    public function update(
        int $id,
        string $userId,
        string $title,
        string $artist,
        string $format,
        ?int $year,
        ?string $barcode,
        ?string $notes,
        string $status,
        ?string $discogsId = null,
        ?string $artworkPath = null,
        ?string $label = null,
        ?string $country = null,
    ): MediaItem {
        $item = $this->mapper->findByUser($id, $userId);
        $item->setTitle($title);
        $item->setArtist($artist);
        $item->setFormat($format);
        $item->setYear($year);
        $item->setBarcode($barcode);
        $item->setNotes($notes);
        $item->setStatus($status);
        $item->setDiscogsId($discogsId);
        // Only overwrite artwork / label / country if the caller explicitly provides a value,
        // so that enriched data is not wiped when the user edits notes or other basic fields.
        if ($artworkPath !== null) {
            $item->setArtworkPath($artworkPath);
        }
        if ($label !== null) {
            $item->setLabel($label);
        }
        if ($country !== null) {
            $item->setCountry($country);
        }
        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    public function delete(int $id, string $userId): void
    {
        $item = $this->mapper->findByUser($id, $userId);
        $this->mapper->delete($item);
    }

    public function deleteAll(string $userId): void
    {
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

        if (!empty($release['title'])) {
            $item->setTitle($release['title']);
        }
        if (!empty($release['artist'])) {
            $item->setArtist($release['artist']);
        }
        if (!empty($release['format'])) {
            $item->setFormat($release['format']);
        }
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
