<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\CrateImageHosts;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class DiscogsService extends AbstractApiService
{
    public function __construct(
        IClientService $clientService,
        ICredentialsManager $credentialsManager,
        LoggerInterface $logger,
        private readonly ?MediaItemMapper $mapper = null,
        private readonly ?IDBConnection $db = null,
        private readonly ?IRootFolder $rootFolder = null,
    ) {
        parent::__construct($clientService, $credentialsManager, $logger);
    }

    private const API_BASE = 'https://api.discogs.com';
    private const USER_AGENT = 'CrateNextcloudApp/0.4 +https://github.com/megamaced/crate';

    /** Format suggested for a release whose formats don't map — a sensible default for music. */
    private const DEFAULT_FORMAT = 'Vinyl';

    /**
     * Discogs' fixed top-level genre vocabulary. The stored `genres` column
     * merges genres and styles into one list (see normaliseRelease), so this
     * is what lets us split a stored value back into the `genre=` and
     * `style=` query parameters the search API expects. Anything not in this
     * list is a style.
     *
     * @var list<string>
     */
    private const GENRE_VOCABULARY = [
        'blues', 'brass & military', "children's", 'classical', 'electronic',
        'folk, world, & country', 'funk / soul', 'hip hop', 'jazz', 'latin',
        'non-music', 'pop', 'reggae', 'rock', 'stage & screen',
    ];

    protected function serviceName(): string
    {
        return 'Discogs';
    }

    protected function credentialKey(): string
    {
        return 'crate/discogs_token';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Search Discogs by free-text query (artist, album, or both).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        return $this->searchRequest($userId, ['q' => $query, 'type' => 'release']);
    }

    /**
     * Records resembling one already in the collection, in the same shape as
     * search() so the add-from-external path can consume either.
     *
     * Discogs has no similarity endpoint, so this stands in for one: search
     * the release's own genre + styles, ranked by how many Discogs users want
     * the record. That reads as "well-regarded records in this style", which
     * is the useful reading of "if you like this…".
     *
     * @param string|null $genres    Stored `genres` value (merged genres + styles)
     * @param string|null $excludeId Release id of the item being viewed, so it can't recommend itself
     * @return array<int, array<string, mixed>>
     */
    public function similarByStyle(
        string $userId,
        ?string $genres,
        ?string $excludeId = null,
        int $limit = 8,
    ): array {
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        [$genre, $styles] = $this->splitGenresAndStyles($genres);
        if ($genre === null && empty($styles)) {
            return [];
        }

        $params = [
            'type'       => 'release',
            'sort'       => 'want',
            'sort_order' => 'desc',
            // Over-fetch: dedup below collapses the many pressings of a
            // popular record down to one row each.
            'per_page'   => '75',
        ];
        if ($genre !== null) {
            $params['genre'] = $genre;
        }
        if (!empty($styles)) {
            // One style only: repeating the parameter changes nothing (verified
            // against the live API — identical result counts), so Discogs keeps
            // just one either way.
            //
            // Result quality tracks how tightly the style is tagged rather than
            // anything we control: a narrow style is excellent (style=Math Rock
            // returns Mars Volta, Black Midi, Shellac) while a broadly-applied
            // one is noisier, because sorting 115k releases by want-count
            // surfaces famous records carrying a stray tag.
            $params['style'] = $styles[0];
        }

        $body = $this->discogsGet($token, self::API_BASE . '/database/search', $params);

        return $this->dedupeByMaster((array)($body['results'] ?? []), $excludeId, $limit);
    }

    /**
     * Collapse a want-sorted release list to one row per master release,
     * keeping the most-wanted pressing of each.
     *
     * @param array<int, mixed> $results
     * @return array<int, array<string, mixed>>
     */
    private function dedupeByMaster(array $results, ?string $excludeId, int $limit): array
    {
        $seen = [];
        $out  = [];

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $id = (string)($result['id'] ?? '');
            if ($id === '' || ($excludeId !== null && $id === $excludeId)) {
                continue;
            }

            // master_id is 0 for standalone releases with no master; fall back
            // to the title so those still dedupe against each other.
            $masterId = (int)($result['master_id'] ?? 0);
            $key = $masterId > 0
                ? 'm:' . $masterId
                : 't:' . mb_strtolower(trim((string)($result['title'] ?? $id)));

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = $this->normalise($result);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Split a stored `genres` value into a single Discogs genre plus the
     * remaining styles, keeping the stored order. Discogs lists a release's
     * styles in no particular order of breadth, so the first is as good a
     * choice as any and at least keeps the query deterministic.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function splitGenresAndStyles(?string $genres): array
    {
        if ($genres === null || trim($genres) === '') {
            return [null, []];
        }

        $genre  = null;
        $styles = [];
        foreach (explode(',', $genres) as $raw) {
            $token = trim($raw);
            if ($token === '') {
                continue;
            }
            if (in_array(mb_strtolower($token), self::GENRE_VOCABULARY, true)) {
                $genre ??= $token;
            } else {
                $styles[] = $token;
            }
        }

        return [$genre, $styles];
    }

    private function getToken(string $userId): string
    {
        return $this->getCredential($userId);
    }

    /**
     * Search Discogs by barcode.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchByBarcode(string $userId, string $barcode): array
    {
        return $this->searchRequest($userId, ['barcode' => $barcode, 'type' => 'release']);
    }

    /**
     * Fetch full release details from Discogs /releases/{id}.
     *
     * Returns a normalised array with keys:
     *   discogsId, title, artist, format, year, artworkUrl,
     *   label, country, genres, tracklist, pressingNotes, discogsArtistId
     *
     * @return array<string, mixed>
     */
    public function getRelease(string $userId, string $releaseId): array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->rawGet($token, self::API_BASE . '/releases/' . rawurlencode($releaseId));
        if (empty($body)) {
            return [];
        }

        return $this->normaliseRelease($body);
    }

    /**
     * Fetch artist profile from Discogs /artists/{id}.
     *
     * Returns a normalised array with keys:
     *   discogsArtistId, name, bio, members
     *
     * @return array<string, mixed>
     */
    public function getArtist(string $userId, string $artistId): array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->rawGet($token, self::API_BASE . '/artists/' . rawurlencode($artistId));
        if (empty($body)) {
            return [];
        }

        return $this->normaliseArtist($body);
    }

    /**
     * Import the user's Discogs collection into the Music category.
     * Skips releases that already exist in the local database matching on
     * Artist, Album (Title), and Format. Creates an import log file in the
     * user's personal files.
     *
     * @param string $userId
     * @param bool $importWantlist Whether to also import the user's wantlist as wanted items
     * @return array{imported: int, skipped: int, total: int, importedAlbums: list<array<string, string>>, skippedAlbums: list<array<string, string>>, logFile: string}
     * @throws \RuntimeException if no token is configured or username cannot be determined
     * @throws \OCA\Crate\Exception\DiscogsRateLimitException on rate limit
     */
    public function importCollection(string $userId, bool $importWantlist = false): array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            throw new \RuntimeException('No Discogs token configured.');
        }

        // 1. Fetch authenticated user's Discogs username
        $identity = $this->discogsGet($token, self::API_BASE . '/oauth/identity');
        $username = trim((string)($identity['username'] ?? ''));
        if ($username === '') {
            throw new \RuntimeException('Failed to retrieve Discogs username.');
        }

        // 2. Retrieve all releases from folder 0 (the full collection)
        $perPage = 100;
        $entries = [];

        $page = 1;
        do {
            $url = self::API_BASE . '/users/' . rawurlencode($username) . '/collection/folders/0/releases';
            $data = $this->discogsGet($token, $url, [
                'page'     => (string)$page,
                'per_page' => (string)$perPage,
            ]);

            $pageReleases = (array)($data['releases'] ?? []);
            if (empty($pageReleases)) {
                break;
            }

            foreach ($pageReleases as $r) {
                if (is_array($r)) {
                    $entries[] = [
                        'release' => $r,
                        'status'  => CrateCategories::STATUS_OWNED,
                    ];
                }
            }

            $totalPages = (int)($data['pagination']['pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        // 2b. Retrieve releases from the user's wantlist if requested
        if ($importWantlist) {
            $page = 1;
            do {
                $url = self::API_BASE . '/users/' . rawurlencode($username) . '/wants';
                $data = $this->discogsGet($token, $url, [
                    'page'     => (string)$page,
                    'per_page' => (string)$perPage,
                ]);

                $pageWants = (array)($data['wants'] ?? []);
                if (empty($pageWants)) {
                    break;
                }

                foreach ($pageWants as $w) {
                    if (is_array($w)) {
                        $entries[] = [
                            'release' => $w,
                            'status'  => CrateCategories::STATUS_WANTED,
                        ];
                    }
                }

                $totalPages = (int)($data['pagination']['pages'] ?? 1);
                $page++;
            } while ($page <= $totalPages);
        }

        // 3. Load existing items for duplicate detection
        $existingKeys = [];
        if ($this->mapper !== null) {
            $existing = $this->mapper->findAll($userId);
            foreach ($existing as $item) {
                $key = $this->dupKey(
                    (string)$item->getArtist(),
                    (string)$item->getTitle(),
                    (string)$item->getFormat(),
                );
                $existingKeys[$key] = true;
            }
        }

        $importedCount  = 0;
        $skippedCount   = 0;
        $importedAlbums = [];
        $skippedAlbums  = [];

        if ($this->db !== null) {
            $this->db->beginTransaction();
        }

        try {
            foreach ($entries as $entry) {
                $release = (array)($entry['release'] ?? []);
                $status  = (string)($entry['status'] ?? CrateCategories::STATUS_OWNED);
                $info    = (array)($release['basic_information'] ?? $release);

                $album = trim((string)($info['title'] ?? ''));

                $artists = (array)($info['artists'] ?? []);
                if (!empty($artists) && isset($artists[0]['name'])) {
                    $artist = (string)preg_replace('/\s*\(\d+\)$/', '', trim((string)$artists[0]['name']));
                } else {
                    $artist = trim((string)($info['artist'] ?? ''));
                }

                if ($artist === '' && str_contains($album, ' - ')) {
                    $parts  = explode(' - ', $album, 2);
                    $artist = trim($parts[0]);
                    $album  = trim($parts[1]);
                }

                // Resolve format
                $segments = [];
                foreach ((array)($info['formats'] ?? []) as $fmt) {
                    if (is_array($fmt)) {
                        $segments[] = [
                            (string)($fmt['name'] ?? ''),
                            ...array_map('strval', array_values((array)($fmt['descriptions'] ?? []))),
                        ];
                    } elseif (is_string($fmt)) {
                        $segments[] = [$fmt];
                    }
                }
                $format = DiscogsFormat::fromSegments($segments);
                if ($format === null && !empty($info['format'])) {
                    if (is_string($info['format'])) {
                        $format = DiscogsFormat::fromExportString($info['format']);
                    } elseif (is_array($info['format'])) {
                        $format = DiscogsFormat::fromTokens(array_map('strval', $info['format']));
                    }
                }
                $format = $format ?? self::DEFAULT_FORMAT;

                if ($artist === '' || $album === '') {
                    $skippedCount++;
                    $skippedAlbums[] = [
                        'artist' => $artist ?: '(Unknown Artist)',
                        'album'  => $album ?: '(Unknown Title)',
                        'format' => $format,
                        'status' => $status,
                        'reason' => 'Missing artist or album title',
                    ];
                    continue;
                }

                // Duplicate check: matched on Artist, Album, and Format
                $key = $this->dupKey($artist, $album, $format);
                if (isset($existingKeys[$key])) {
                    $skippedCount++;
                    $skippedAlbums[] = [
                        'artist' => $artist,
                        'album'  => $album,
                        'format' => $format,
                        'status' => $status,
                        'reason' => 'Already in collection',
                    ];
                    continue;
                }

                $item = new MediaItem();
                $item->setUserId($userId);
                $item->setArtist($artist);
                $item->setTitle($album);
                $item->setFormat($format);
                $item->setCategory(CrateCategories::MUSIC);
                $item->setStatus($status);

                $year = isset($info['year']) ? (int)$info['year'] : null;
                if ($year === 0) {
                    $year = null;
                }
                $item->setYear($year);

                $discogsId = (string)($info['id'] ?? $release['id'] ?? '');
                if ($discogsId !== '') {
                    $item->setDiscogsId($discogsId);
                }

                // Labels
                $labels = (array)($info['labels'] ?? []);
                $labelParts = array_map(
                    fn(array $l) => trim(
                        ($l['name'] ?? '')
                        . (!empty($l['catno']) && $l['catno'] !== 'none' ? ' – ' . $l['catno'] : '')
                    ),
                    array_slice($labels, 0, 4),
                );
                $label = implode(', ', array_filter($labelParts)) ?: null;
                $item->setLabel($label);

                // Artwork
                $artworkUrl = $info['cover_image'] ?? $info['thumb'] ?? null;
                if ($artworkUrl !== null && CrateImageHosts::isValidArtworkPath($artworkUrl)) {
                    $item->setArtworkPath($artworkUrl);
                }

                if (!empty($release['notes'])) {
                    if (is_array($release['notes'])) {
                        $noteValues = array_filter(array_column($release['notes'], 'value'));
                        if (!empty($noteValues)) {
                            $item->setNotes(implode("\n", $noteValues));
                        }
                    } elseif (is_string($release['notes'])) {
                        $notes = trim($release['notes']);
                        if ($notes !== '') {
                            $item->setNotes($notes);
                        }
                    }
                }

                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $item->setCreatedAt($now);
                $item->setUpdatedAt($now);

                if ($this->mapper !== null) {
                    if ($this->db !== null) {
                        $this->db->executeStatement('SAVEPOINT crate_discogs_import_row');
                    }
                    try {
                        $this->mapper->insert($item);
                        if ($this->db !== null) {
                            $this->db->executeStatement('RELEASE SAVEPOINT crate_discogs_import_row');
                        }
                        $existingKeys[$key] = true;
                        $importedCount++;
                        $importedAlbums[] = [
                            'artist' => $artist,
                            'album'  => $album,
                            'format' => $format,
                            'status' => $status,
                        ];
                    } catch (\Throwable $e) {
                        if ($this->db !== null) {
                            $this->db->executeStatement('ROLLBACK TO SAVEPOINT crate_discogs_import_row');
                        }
                        $skippedCount++;
                        $skippedAlbums[] = [
                            'artist' => $artist,
                            'album'  => $album,
                            'format' => $format,
                            'status' => $status,
                            'reason' => 'Database error: ' . $e->getMessage(),
                        ];
                    }
                } else {
                    $existingKeys[$key] = true;
                    $importedCount++;
                    $importedAlbums[] = [
                        'artist' => $artist,
                        'album'  => $album,
                        'format' => $format,
                        'status' => $status,
                    ];
                }
            }

            if ($this->db !== null) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($this->db !== null) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $totalCount  = $importedCount + $skippedCount;
        $logFileName = $this->writeImportLog($userId, $importedCount, $skippedCount, $totalCount, $importedAlbums, $skippedAlbums);

        return [
            'imported'       => $importedCount,
            'skipped'        => $skippedCount,
            'total'          => $totalCount,
            'importedAlbums' => $importedAlbums,
            'skippedAlbums'  => $skippedAlbums,
            'logFile'        => $logFileName,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Perform a GET request against the Discogs API.
     *
     * Handles User-Agent, auth header, and rate-limit detection centrally.
     *
     * @param array<string, string> $query
     * @return array<string, mixed>
     * @throws \OCA\Crate\Exception\DiscogsRateLimitException
     * @throws \Exception for other HTTP errors
     */
    private function discogsGet(string $token, string $url, array $query = []): array
    {
        $options = [
            'headers' => [
                'User-Agent'    => self::USER_AGENT,
                'Accept'        => 'application/json',
                'Authorization' => 'Discogs token=' . $token,
            ],
            'timeout' => 10,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                throw new \OCA\Crate\Exception\DiscogsRateLimitException(
                    'Discogs rate limit exceeded.',
                    429,
                    $e,
                );
            }
            throw $e;
        }

        return json_decode($response->getBody(), true) ?? [];
    }

    /**
     * Run a database/search query and return normalised results.
     *
     * @param array<string, string> $params
     * @return array<int, array<string, mixed>>
     */
    private function searchRequest(string $userId, array $params): array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        $params['per_page'] = '10';
        $body = $this->discogsGet($token, self::API_BASE . '/database/search', $params);

        $results = $body['results'] ?? [];

        return array_values(array_map(
            fn(array $r) => $this->normalise($r),
            array_slice($results, 0, 10),
        ));
    }

    /**
     * Perform a GET against an absolute Discogs URL and return the decoded body.
     * Non-rate-limit errors are silently swallowed (returns empty array).
     *
     * @return array<string, mixed>
     */
    private function rawGet(string $token, string $url): array
    {
        try {
            return $this->discogsGet($token, $url);
        } catch (\OCA\Crate\Exception\DiscogsRateLimitException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logWarning($url, $e);
            return [];
        }
    }

    /**
     * Normalise a Discogs search result into a consistent shape for the frontend.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalise(array $result): array
    {
        // Discogs title format: "Artist Name - Album Title"
        $rawTitle = $result['title'] ?? '';
        $parts    = explode(' - ', $rawTitle, 2);
        $artist   = trim($parts[0] ?? '');
        $album    = trim($parts[1] ?? $rawTitle);

        $format = DiscogsFormat::fromTokens(array_map('strval', (array)($result['format'] ?? [])))
            ?? self::DEFAULT_FORMAT;

        $year = isset($result['year']) ? (int)$result['year'] : null;
        if ($year === 0) {
            $year = null;
        }

        return [
            'discogsId' => (string)($result['id'] ?? ''),
            'artist'    => $artist,
            'title'     => $album,
            'format'    => $format,
            'year'      => $year,
            'thumb'     => $result['thumb'] ?? null,
            'label'     => implode(', ', array_slice((array)($result['label'] ?? []), 0, 2)),
            'country'   => $result['country'] ?? null,
        ];
    }

    /**
     * Normalise a Discogs /releases/{id} response.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function normaliseRelease(array $r): array
    {
        // First artist name — Discogs appends " (N)" disambiguation suffixes
        $artists    = (array)($r['artists'] ?? []);
        $artistName = preg_replace('/\s*\(\d+\)$/', '', trim($artists[0]['name'] ?? ''));

        $discogsArtistId = isset($artists[0]['id']) ? (string)$artists[0]['id'] : null;

        // Labels — "Name – cat#" joined, up to 4 entries
        $labelParts = array_map(
            fn(array $l) => trim(
                ($l['name'] ?? '')
                . (!empty($l['catno']) && $l['catno'] !== 'none' ? ' – ' . $l['catno'] : '')
            ),
            array_slice((array)($r['labels'] ?? []), 0, 4),
        );
        $label = implode(', ', $labelParts) ?: null;

        // Genres + styles merged, deduplicated
        $genreList = array_unique(array_merge(
            array_values((array)($r['genres'] ?? [])),
            array_values((array)($r['styles'] ?? [])),
        ));
        $genres = $genreList ? implode(', ', $genreList) : null;

        // Tracklist — filter section headings (empty title), keep position/title/duration
        $tracklist = array_values(array_map(
            fn(array $t) => [
                'position' => $t['position'] ?? '',
                'title'    => $t['title'] ?? '',
                'duration' => $t['duration'] ?? '',
            ],
            array_filter(
                (array)($r['tracklist'] ?? []),
                fn(array $t) => !empty($t['title']),
            ),
        ));

        // Full-size primary image (fall back to first available)
        $images     = (array)($r['images'] ?? []);
        $artworkUrl = null;
        foreach ($images as $img) {
            if (($img['type'] ?? '') === 'primary' && !empty($img['uri'])) {
                $artworkUrl = $img['uri'];
                break;
            }
        }
        if ($artworkUrl === null) {
            foreach ($images as $img) {
                if (!empty($img['uri'])) {
                    $artworkUrl = $img['uri'];
                    break;
                }
            }
        }

        // Every format as [name, ...descriptions]: a box set lists the discs
        // it holds as further formats after its own "Box Set" entry
        $segments = [];
        foreach ((array)($r['formats'] ?? []) as $fmt) {
            $segments[] = [
                (string)($fmt['name'] ?? ''),
                ...array_map('strval', array_values((array)($fmt['descriptions'] ?? []))),
            ];
        }
        $format = DiscogsFormat::fromSegments($segments) ?? self::DEFAULT_FORMAT;

        $year = isset($r['year']) ? (int)$r['year'] : null;
        if ($year === 0) {
            $year = null;
        }

        return [
            'discogsId'       => (string)($r['id'] ?? ''),
            'title'           => $r['title'] ?? '',
            'artist'          => $artistName,
            'format'          => $format,
            'year'            => $year,
            'artworkUrl'      => $artworkUrl,
            'label'           => $label,
            'country'         => $r['country'] ?? null,
            'genres'          => $genres,
            'tracklist'       => $tracklist,
            'pressingNotes'   => $r['notes'] ?? null,
            'discogsArtistId' => $discogsArtistId,
        ];
    }

    /**
     * Normalise a Discogs /artists/{id} response.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function normaliseArtist(array $a): array
    {
        $members = array_values(array_map(
            fn(array $m) => $m['name'] ?? '',
            array_filter(
                (array)($a['members'] ?? []),
                fn(array $m) => !empty($m['name']),
            ),
        ));

        return [
            'discogsArtistId' => (string)($a['id'] ?? ''),
            'name'            => $a['name'] ?? '',
            'bio'             => trim($a['profile'] ?? '') ?: null,
            'members'         => $members ?: null,
        ];
    }

    private function dupKey(string $artist, string $album, string $format): string
    {
        return mb_strtolower(trim($artist), 'UTF-8') . '||'
            . mb_strtolower(trim($album), 'UTF-8') . '||'
            . mb_strtolower(trim($format), 'UTF-8');
    }

    /**
     * Write an import log file to the user's personal files.
     * The filename is discogs-import-log-YYYYMMDD.txt. If that file already exists,
     * appends -1, -2, -3, etc. to the end before the extension.
     *
     * @param list<array<string, string>> $importedAlbums
     * @param list<array<string, string>> $skippedAlbums
     * @return string The filename of the log file written
     */
    private function writeImportLog(
        string $userId,
        int $importedCount,
        int $skippedCount,
        int $totalCount,
        array $importedAlbums,
        array $skippedAlbums,
    ): string {
        $baseName = 'discogs-import-log-' . date('Ymd');
        $fileName = $baseName . '.txt';

        $lines = [];
        $lines[] = 'Discogs Collection Import Log';
        $lines[] = "Import results: {$importedCount} imported, {$skippedCount} skipped, {$totalCount} total.";
        $lines[] = '';
        $lines[] = 'Successfully imported albums:';
        if (empty($importedAlbums)) {
            $lines[] = 'None';
        } else {
            foreach ($importedAlbums as $item) {
                $statusSuffix = ($item['status'] ?? CrateCategories::STATUS_OWNED) === CrateCategories::STATUS_WANTED ? ' [Wanted]' : '';
                $lines[] = "- {$item['artist']} - {$item['album']} ({$item['format']}){$statusSuffix}";
            }
        }
        $lines[] = '';
        $lines[] = 'Skipped albums:';
        if (empty($skippedAlbums)) {
            $lines[] = 'None';
        } else {
            foreach ($skippedAlbums as $item) {
                $statusSuffix = ($item['status'] ?? CrateCategories::STATUS_OWNED) === CrateCategories::STATUS_WANTED ? ' [Wanted]' : '';
                $entry = "- {$item['artist']} - {$item['album']} ({$item['format']}){$statusSuffix}";
                if (!empty($item['reason'])) {
                    $entry .= ": {$item['reason']}";
                }
                $lines[] = $entry;
            }
        }
        $lines[] = '';
        $lines[] = "Summary: {$importedCount} records imported, {$skippedCount} skipped out of {$totalCount} records.";
        $content = implode("\n", $lines) . "\n";

        if ($this->rootFolder === null) {
            return $fileName;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            if ($userFolder->nodeExists($fileName)) {
                $i = 1;
                while ($userFolder->nodeExists("{$baseName}-{$i}.txt")) {
                    $i++;
                }
                $fileName = "{$baseName}-{$i}.txt";
            }

            $userFolder->newFile($fileName, $content);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write Discogs import log file: ' . $e->getMessage(), [
                'app'  => 'crate',
                'user' => $userId,
            ]);
        }

        return $fileName;
    }
}

