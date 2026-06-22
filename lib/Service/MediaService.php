<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Dto\MediaItemData;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class MediaService
{
    /** Per-user config key holding the timestamp of the last bulk wipe. */
    public const WIPED_AT_KEY = 'wiped_at';

    public function __construct(
        private readonly MediaItemMapper $mapper,
        private readonly PlaylistItemMapper $playlistItemMapper,
        private readonly CrateShareMapper $shareMapper,
        private readonly PlaylistMapper $playlistMapper,
        private readonly IAppDataFactory $appDataFactory,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly ActivityService $activityService,
        private readonly IConfig $config,
    ) {
    }

    /**
     * Timestamp of the last bulk wipe for $userId, or null if never wiped.
     * Clients use this to detect when their local cache must be discarded —
     * delta sync can't otherwise observe deletions.
     */
    public function getWipedAt(string $userId): ?string
    {
        $value = $this->config->getUserValue($userId, 'crate', self::WIPED_AT_KEY, '');
        return $value === '' ? null : $value;
    }

    private function markWiped(string $userId): void
    {
        $this->config->setUserValue(
            $userId,
            'crate',
            self::WIPED_AT_KEY,
            (new \DateTime())->format('Y-m-d H:i:s'),
        );
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

    /** @return int[] */
    public function findIdsWithEnrichmentForUser(string $userId): array
    {
        return $this->mapper->findIdsWithEnrichmentForUser($userId);
    }

    public function find(int $id, string $userId): MediaItem
    {
        return $this->mapper->findByUser($id, $userId);
    }

    /**
     * Read an item visible to the viewer — they own it, or it's covered by an
     * album / library / category share to them. Used by read-only callers; do
     * not use for writes.
     */
    public function findVisible(int $id, string $viewerUserId): MediaItem
    {
        return $this->mapper->findVisibleForUser($id, $viewerUserId);
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
        $item->setPurchasePrice($data->purchasePrice);
        $item->setPurchasePriceCurrency($data->purchasePriceCurrency);
        $item->setCategory($data->category ?? \OCA\Crate\CrateCategories::MUSIC);
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $item->setCreatedAt($now);
        $item->setUpdatedAt($now);
        $item = $this->mapper->insert($item);
        $this->activityService->itemCreated($item, $userId);
        return $item;
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
        // Purchase price + currency are user-entered and never touched by enrichment,
        // so the AddEditModal sends the full payload and we always overwrite. Null
        // explicitly clears the field. This matches year/notes/status behaviour.
        $item->setPurchasePrice($data->purchasePrice);
        $item->setPurchasePriceCurrency($data->purchasePriceCurrency);
        // Preserve existing category on partial updates (caller omitted it)
        if ($data->category !== null) {
            $item->setCategory($data->category);
        }
        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        $item = $this->mapper->update($item);
        $this->activityService->itemUpdated($item, $userId);
        return $item;
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
        $this->deletePhotoFiles($id);

        $this->activityService->itemDeleted($item, $userId);

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

    /**
     * Remove every user-uploaded photo file for an item across both slots,
     * mirroring deleteArtworkFiles. Called after the row delete commits so a
     * failed unlink does not roll the DB delete back.
     */
    private function deletePhotoFiles(int $itemId): void
    {
        try {
            $folder = $this->appDataFactory->get('crate')->getFolder('photos');
            foreach ([1, 2] as $slot) {
                foreach (['.jpg', '.png', '.webp', '.gif'] as $ext) {
                    try {
                        $folder->getFile('photo_' . $itemId . '_' . $slot . $ext)->delete();
                    } catch (NotFoundException) {
                    }
                }
            }
        } catch (NotFoundException) {
        }
    }

    /**
     * Convenience wipe — removes everything for a user (all five categories
     * + playlists). Delegates to the scoped variant.
     */
    public function wipeUserData(string $userId): void
    {
        $this->wipeScopes(
            $userId,
            array_merge(CrateCategories::ALL, ['playlists']),
        );
    }

    /**
     * Scoped wipe — removes items in the specified categories and / or
     * playlists. Valid scope values are the five CrateCategories (music,
     * film, book, game, comic) and the literal 'playlists' (which also
     * removes playlist shares). All DB work runs in a single transaction;
     * artwork files are deleted afterwards so a filesystem failure cannot
     * undo the DB commit.
     *
     * Unknown scopes are silently ignored — validation is the controller's
     * job.
     *
     * @param list<string> $scopes
     */
    public function wipeScopes(string $userId, array $scopes): void
    {
        $categories = array_values(array_intersect($scopes, CrateCategories::ALL));
        $wipePlaylists = in_array('playlists', $scopes, true);

        if (empty($categories) && !$wipePlaylists) {
            return;
        }

        // Load before the transaction so we know which artwork files to sweep.
        $itemsToDelete = [];
        foreach ($categories as $category) {
            foreach ($this->mapper->findAll($userId, $category) as $item) {
                $itemsToDelete[] = $item;
            }
        }
        $playlistsToDelete = $wipePlaylists ? $this->playlistMapper->findAll($userId) : [];

        $this->db->beginTransaction();
        try {
            if ($wipePlaylists) {
                foreach ($playlistsToDelete as $playlist) {
                    $this->shareMapper->deleteByShareable('playlist', $playlist->getId());
                    $this->playlistItemMapper->deleteByPlaylist($playlist->getId());
                }
                $this->playlistMapper->deleteAllByUser($userId);
            }

            foreach ($itemsToDelete as $item) {
                // FK cascade handles playlist_items, but we still clear the
                // polymorphic share rows and (where applicable) keep the
                // playlist_items delete as belt-and-braces for legacy installs
                // that predate migration 0001's consolidated FK schema.
                $this->playlistItemMapper->deleteByMediaItem($item->getId());
                $this->shareMapper->deleteByShareable('album', $item->getId());
            }
            foreach ($categories as $category) {
                $this->mapper->deleteAllByUserAndCategory($userId, $category);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach ($itemsToDelete as $item) {
            $this->deleteArtworkFiles($item->getId());
            $this->deletePhotoFiles($item->getId());
        }

        $this->markWiped($userId);

        $this->logger->warning(
            'Wiped Crate data for user {user}: scopes={scopes}, items={items}, playlists={playlists}',
            [
                'user'      => $userId,
                'scopes'    => implode(',', $scopes),
                'items'     => count($itemsToDelete),
                'playlists' => count($playlistsToDelete),
                'app'       => 'crate',
            ],
        );
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
        return $this->applyEnrichmentFields($id, $userId, [
            'title' => $movie['title'] ?? null,
            'artist' => $movie['artist'] ?? null,
            'year' => $movie['year'] ?? null,
            'genres' => $movie['genres'] ?? null,
            'label' => $movie['label'] ?? null,
            'country' => $movie['country'] ?? null,
            'overview' => $movie['overview'] ?? null,
            'artworkUrl' => $movie['artworkUrl'] ?? null,
            'enrichmentId' => $movie['tmdbId'] ?? null,
            'enrichmentArtistId' => $movie['directorId'] ?? null,
        ]);
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
        $genres = $work['genres'] ?? $doc['genres'] ?? null;
        return $this->applyEnrichmentFields($id, $userId, [
            'title' => $doc['title'] ?? null,
            'artist' => $doc['artist'] ?? null,
            'year' => $doc['year'] ?? null,
            'label' => $doc['label'] ?? null,
            'barcode' => $doc['barcode'] ?? null,
            'genres' => $genres,
            'overview' => $work['overview'] ?? null,
            'artworkUrl' => $work['artworkUrl'] ?? null,
            'artistBio' => $work['authorBio'] ?? null,
            'enrichmentArtistId' => $work['authorKey'] ?? null,
            'enrichmentId' => $doc['workKey'] ?? null,
        ]);
    }

    /**
     * Enrich a game item with RAWG game data.
     * game comes from RawgService::getGame().
     *
     * @param array<string, mixed> $game
     */
    public function applyRawgData(int $id, string $userId, array $game): MediaItem
    {
        return $this->applyEnrichmentFields($id, $userId, [
            'title' => $game['title'] ?? null,
            'artist' => $game['artist'] ?? null,
            'year' => $game['year'] ?? null,
            'label' => $game['label'] ?? null,
            'genres' => $game['genres'] ?? null,
            'overview' => $game['overview'] ?? null,
            'artworkUrl' => $game['artworkUrl'] ?? null,
            'enrichmentId' => $game['rawgId'] ?? null,
        ]);
    }

    /**
     * Enrich a comic item with ComicVine volume data.
     * volume comes from ComicVineService::getVolume().
     *
     * @param array<string, mixed> $volume
     */
    public function applyComicVineData(int $id, string $userId, array $volume): MediaItem
    {
        return $this->applyEnrichmentFields($id, $userId, [
            'title' => $volume['title'] ?? null,
            'year' => $volume['year'] ?? null,
            'label' => $volume['label'] ?? null,
            'genres' => $volume['genres'] ?? null,
            'overview' => $volume['overview'] ?? null,
            'artworkUrl' => $volume['artworkUrl'] ?? null,
            'enrichmentId' => $volume['comicVineId'] ?? null,
        ]);
    }

    /**
     * Apply a normalised set of enrichment fields to an item.
     *
     * Supported keys: title, artist, year, genres, label, country, barcode,
     * overview, artworkUrl, enrichmentId, enrichmentArtistId, artistBio,
     * artistMembers, tracklist.
     *
     * @param array<string, mixed> $fields
     */
    private function applyEnrichmentFields(int $id, string $userId, array $fields): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);
        $this->snapshotOriginals($item);

        $map = [
            'title'               => 'setTitle',
            'artist'              => 'setArtist',
            'label'               => 'setLabel',
            'country'             => 'setCountry',
            'barcode'             => 'setBarcode',
            'genres'              => 'setGenres',
            'overview'            => 'setPressingNotes',
            'artworkUrl'          => 'setArtworkPath',
            'enrichmentId'        => 'setDiscogsId',
            'enrichmentArtistId'  => 'setDiscogsArtistId',
            'artistBio'           => 'setArtistBio',
        ];

        foreach ($map as $key => $setter) {
            if (!empty($fields[$key])) {
                $item->$setter($fields[$key]);
            }
        }

        // Year uses isset() — 0 is a valid value
        if (isset($fields['year'])) {
            $item->setYear($fields['year']);
        }

        // JSON-encoded array fields
        if (isset($fields['tracklist']) && is_array($fields['tracklist'])) {
            $item->setTracklist(json_encode($fields['tracklist']));
        }
        if (isset($fields['artistMembers']) && is_array($fields['artistMembers'])) {
            $item->setArtistMembers(json_encode($fields['artistMembers']));
        }

        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));
        $item = $this->mapper->update($item);
        $this->activityService->itemEnriched($item, $userId);
        return $item;
    }

    /**
     * Snapshot all user-entered fields that enrichment may overwrite, on the
     * first enrichment only. stripEnrichment() restores from these.
     */
    private function snapshotOriginals(MediaItem $item): void
    {
        if ($item->getOriginalTitle() !== null) {
            return;
        }
        $item->setOriginalTitle($item->getTitle());
        $item->setOriginalArtist($item->getArtist());
        $item->setOriginalYear($item->getYear());
        $item->setOriginalArtworkPath($item->getArtworkPath());
        $item->setOriginalLabel($item->getLabel());
        $item->setOriginalCountry($item->getCountry());
    }

    /**
     * Strip all enrichment fields from an item, preserving the
     * user-entered fields (title, artist, format, year, notes, status, artwork,
     * label, country). The discogsId is also cleared so the item is treated
     * as unenriched.
     */
    public function stripEnrichment(int $id, string $userId): MediaItem
    {
        $item = $this->mapper->findByUser($id, $userId);

        // Determine whether the pre-enrichment state had user-uploaded artwork.
        // If so, preserve the file on disk. Otherwise, delete stale cache files
        // so re-enrichment with a new URL doesn't serve the old cached image.
        $originalArtwork = $item->getOriginalArtworkPath();
        $shouldDeleteFiles = ($originalArtwork !== 'local');

        // Restore pre-enrichment values if a snapshot was taken, then clear it.
        if ($item->getOriginalTitle() !== null) {
            $item->setTitle($item->getOriginalTitle());
            $item->setArtist($item->getOriginalArtist());
            $item->setYear($item->getOriginalYear());
            $item->setArtworkPath($item->getOriginalArtworkPath());
            $item->setLabel($item->getOriginalLabel());
            $item->setCountry($item->getOriginalCountry());
            $item->setOriginalTitle(null);
            $item->setOriginalArtist(null);
            $item->setOriginalYear(null);
            $item->setOriginalArtworkPath(null);
            $item->setOriginalLabel(null);
            $item->setOriginalCountry(null);
        } else {
            // No snapshot — item was never enriched (or was enriched before this
            // migration). Clear label/country since they may be enrichment-sourced.
            $item->setLabel(null);
            $item->setCountry(null);
        }

        $item->setGenres(null);
        $item->setTracklist(null);
        $item->setPressingNotes(null);
        $item->setDiscogsArtistId(null);
        $item->setArtistBio(null);
        $item->setArtistMembers(null);
        $item->setDiscogsId(null);
        $item->setUpdatedAt((new \DateTime())->format('Y-m-d H:i:s'));

        // Delete cached artwork files so stale images are not served when the
        // item is re-enriched with a different artwork URL.
        if ($shouldDeleteFiles) {
            $this->deleteArtworkFiles($id);
        }

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
        return $this->applyEnrichmentFields($id, $userId, [
            'title' => $release['title'] ?? null,
            'artist' => $release['artist'] ?? null,
            'year' => $release['year'] ?? null,
            'label' => $release['label'] ?? null,
            'country' => $release['country'] ?? null,
            'genres' => $release['genres'] ?? null,
            'tracklist' => $release['tracklist'] ?? null,
            'overview' => $release['pressingNotes'] ?? null,
            'enrichmentArtistId' => $release['discogsArtistId'] ?? null,
            'artworkUrl' => $release['artworkUrl'] ?? null,
            'artistBio' => $artist['bio'] ?? null,
            'artistMembers' => $artist['members'] ?? null,
        ]);
    }
}
