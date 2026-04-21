<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Dto\MediaItemData;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class MediaService
{
    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly CrateShareMapper $shareMapper,
        private readonly PlaylistMapper $playlistMapper,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
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

        $this->db->beginTransaction();
        try {
            $this->playlistItemMapper->deleteByMediaItem($id);
            $this->shareMapper->deleteByShareable('album', $id);
            $this->mapper->delete($item);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Files are deleted after commit — a failed unlink shouldn't undo the DB delete.
        $this->deleteArtworkFiles($id);

        $this->logger->info('Deleted media item {id} for user {user}', [
            'id'   => $id,
            'user' => $userId,
            'app'  => 'crate',
        ]);
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
     * Wipe everything for a user — media items, playlists, playlist items,
     * and shares — in a single transaction. Artwork files are cleared after
     * commit so a filesystem failure doesn't leave orphan DB rows.
     */
    public function wipeUserData(string $userId): void
    {
        $items     = $this->findAll($userId);
        $playlists = $this->playlistMapper->findAll($userId);

        $this->db->beginTransaction();
        try {
            foreach ($playlists as $playlist) {
                $this->shareMapper->deleteByShareable('playlist', $playlist->getId());
                $this->playlistItemMapper->deleteByPlaylist($playlist->getId());
            }
            $this->playlistMapper->deleteAllByUser($userId);

            foreach ($items as $item) {
                $this->playlistItemMapper->deleteByMediaItem($item->getId());
                $this->shareMapper->deleteByShareable('album', $item->getId());
            }
            $this->mapper->deleteAllByUser($userId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach ($items as $item) {
            $this->deleteArtworkFiles($item->getId());
        }

        $this->logger->warning('Wiped all Crate data for user {user} ({items} items, {playlists} playlists)', [
            'items'     => count($items),
            'playlists' => count($playlists),
            'user'      => $userId,
            'app'       => 'crate',
        ]);
    }

    /**
     * Delete all media items for a user, including related data cleanup.
     * Handles artwork files, playlist-item references, and album shares.
     * Database operations run in a single transaction so a mid-loop failure
     * leaves no orphaned playlist_item / share rows.
     */
    public function deleteAllForUser(string $userId): void
    {
        $items = $this->findAll($userId);

        $this->db->beginTransaction();
        try {
            foreach ($items as $item) {
                $this->playlistItemMapper->deleteByMediaItem($item->getId());
                $this->shareMapper->deleteByShareable('album', $item->getId());
            }
            $this->mapper->deleteAllByUser($userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Artwork files are deleted outside the transaction — if the filesystem
        // delete fails we still want the DB rows gone, and stale files are
        // harmless (they'll be overwritten or garbage-collected).
        foreach ($items as $item) {
            $this->deleteArtworkFiles($item->getId());
        }

        $this->logger->warning('Deleted all {count} media items for user {user}', [
            'count' => count($items),
            'user'  => $userId,
            'app'   => 'crate',
        ]);
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
     * Enrich a film item with TMDB movie data.
     * movie comes from TmdbService::getMovie().
     *
     * @param array<string, mixed> $movie
     */
    public function applyTmdbData(int $id, string $userId, array $movie): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        if ($item->getOriginalTitle() === null) {
            $item->setOriginalTitle($item->getTitle());
            $item->setOriginalArtist($item->getArtist());
            $item->setOriginalYear($item->getYear());
            $item->setOriginalArtworkPath($item->getArtworkPath());
        }

        if (!empty($movie['title'])) {
            $item->setTitle($movie['title']);
        }
        if (!empty($movie['artist'])) {
            $item->setArtist($movie['artist']);
        }
        if (isset($movie['year'])) {
            $item->setYear($movie['year']);
        }
        if (!empty($movie['genres'])) {
            $item->setGenres($movie['genres']);
        }
        if (!empty($movie['label'])) {
            $item->setLabel($movie['label']);
        }
        if (!empty($movie['country'])) {
            $item->setCountry($movie['country']);
        }
        if (!empty($movie['overview'])) {
            $item->setPressingNotes($movie['overview']);
        }
        if (!empty($movie['artworkUrl'])) {
            $item->setArtworkPath($movie['artworkUrl']);
        }
        if (!empty($movie['tmdbId'])) {
            $item->setDiscogsId($movie['tmdbId']);
        }
        if (!empty($movie['directorId'])) {
            $item->setDiscogsArtistId($movie['directorId']);
        }

        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    /**
     * Enrich a book item with Open Library work data.
     * work comes from OpenLibraryService::getWork(); doc is the search result.
     *
     * @param array<string, mixed> $doc  Basic fields from search result (title, artist, year, etc.)
     * @param array<string, mixed> $work Full work details
     */
    public function applyOpenLibraryData(int $id, string $userId, array $doc, array $work): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        if ($item->getOriginalTitle() === null) {
            $item->setOriginalTitle($item->getTitle());
            $item->setOriginalArtist($item->getArtist());
            $item->setOriginalYear($item->getYear());
            $item->setOriginalArtworkPath($item->getArtworkPath());
        }

        if (!empty($doc['title'])) {
            $item->setTitle($doc['title']);
        }
        if (!empty($doc['artist'])) {
            $item->setArtist($doc['artist']);
        }
        if (isset($doc['year'])) {
            $item->setYear($doc['year']);
        }
        if (!empty($doc['label'])) {
            $item->setLabel($doc['label']);
        }
        if (!empty($doc['barcode'])) {
            $item->setBarcode($doc['barcode']);
        }

        // Work detail fields take precedence over search fields
        $genres = $work['genres'] ?? $doc['genres'] ?? null;
        if (!empty($genres)) {
            $item->setGenres($genres);
        }
        if (!empty($work['overview'])) {
            $item->setPressingNotes($work['overview']);
        }
        if (!empty($work['artworkUrl'])) {
            $item->setArtworkPath($work['artworkUrl']);
        }
        if (!empty($work['authorBio'])) {
            $item->setArtistBio($work['authorBio']);
        }
        if (!empty($work['authorKey'])) {
            $item->setDiscogsArtistId($work['authorKey']);
        }
        if (!empty($doc['workKey'])) {
            $item->setDiscogsId($doc['workKey']);
        }

        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    /**
     * Enrich a game item with RAWG game data.
     * game comes from RawgService::getGame().
     *
     * @param array<string, mixed> $game
     */
    public function applyRawgData(int $id, string $userId, array $game): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        if ($item->getOriginalTitle() === null) {
            $item->setOriginalTitle($item->getTitle());
            $item->setOriginalArtist($item->getArtist());
            $item->setOriginalYear($item->getYear());
            $item->setOriginalArtworkPath($item->getArtworkPath());
        }

        if (!empty($game['title'])) {
            $item->setTitle($game['title']);
        }
        if (!empty($game['artist'])) {
            $item->setArtist($game['artist']);
        }
        if (isset($game['year'])) {
            $item->setYear($game['year']);
        }
        if (!empty($game['label'])) {
            $item->setLabel($game['label']);
        }
        if (!empty($game['genres'])) {
            $item->setGenres($game['genres']);
        }
        if (!empty($game['overview'])) {
            $item->setPressingNotes($game['overview']);
        }
        if (!empty($game['artworkUrl'])) {
            $item->setArtworkPath($game['artworkUrl']);
        }
        if (!empty($game['rawgId'])) {
            $item->setDiscogsId($game['rawgId']);
        }

        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    /**
     * Enrich a comic item with ComicVine volume data.
     * volume comes from ComicVineService::getVolume().
     *
     * @param array<string, mixed> $volume
     */
    public function applyComicVineData(int $id, string $userId, array $volume): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        if ($item->getOriginalTitle() === null) {
            $item->setOriginalTitle($item->getTitle());
            $item->setOriginalArtist($item->getArtist());
            $item->setOriginalYear($item->getYear());
            $item->setOriginalArtworkPath($item->getArtworkPath());
        }

        if (!empty($volume['title'])) {
            $item->setTitle($volume['title']);
        }
        if (isset($volume['year'])) {
            $item->setYear($volume['year']);
        }
        if (!empty($volume['label'])) {
            $item->setLabel($volume['label']);
        }
        if (!empty($volume['genres'])) {
            $item->setGenres($volume['genres']);
        }
        if (!empty($volume['overview'])) {
            $item->setPressingNotes($volume['overview']);
        }
        if (!empty($volume['artworkUrl'])) {
            $item->setArtworkPath($volume['artworkUrl']);
        }
        if (!empty($volume['comicVineId'])) {
            $item->setDiscogsId($volume['comicVineId']);
        }

        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        return $this->mapper->update($item);
    }

    /**
     * Strip all enrichment fields from an item, preserving the
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
