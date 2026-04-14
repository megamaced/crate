<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;

class DiscogsService
{
    private const API_BASE = 'https://api.discogs.com';
    private const USER_AGENT = 'CrateNextcloudApp/0.1 +https://gitea.macecloud.co.uk/macebox/crate';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly IConfig $config,
    ) {
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getToken(string $userId): string
    {
        return $this->config->getUserValue($userId, 'crate', 'discogs_token', '');
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

        $params['token']    = $token;
        $params['per_page'] = '10';

        $client = $this->clientService->newClient();
        try {
            $response = $client->get(self::API_BASE . '/database/search', [
                'query'   => $params,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'application/json',
                ],
                'timeout' => 10,
            ]);
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

        $body    = json_decode($response->getBody(), true);
        $results = $body['results'] ?? [];

        return array_values(array_map(
            fn(array $r) => $this->normalise($r),
            array_slice($results, 0, 10),
        ));
    }

    /**
     * Perform a GET against an absolute Discogs URL and return the decoded body.
     *
     * @return array<string, mixed>
     */
    private function rawGet(string $token, string $url): array
    {
        $client = $this->clientService->newClient();
        try {
            $response = $client->get($url, [
                'query'   => ['token' => $token],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept'     => 'application/json',
                ],
                'timeout' => 10,
            ]);
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                throw new \OCA\Crate\Exception\DiscogsRateLimitException(
                    'Discogs rate limit exceeded.',
                    429,
                    $e,
                );
            }
            return [];
        }

        return json_decode($response->getBody(), true) ?? [];
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

        $formats = array_map('strtolower', (array)($result['format'] ?? []));
        $format  = $this->mapFormat($formats);

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

        // Combine format name + descriptions for more precise mapping
        $fmtTokens = [];
        if (!empty($r['formats'][0]['name'])) {
            $fmtTokens[] = strtolower((string)$r['formats'][0]['name']);
        }
        foreach ((array)($r['formats'][0]['descriptions'] ?? []) as $desc) {
            $fmtTokens[] = strtolower((string)$desc);
        }
        $format = $this->mapFormat($fmtTokens);

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

    /**
     * Map an array of Discogs format tokens (name + descriptions, lowercased)
     * to our canonical format string.
     *
     * @param string[] $formats
     */
    private function mapFormat(array $formats): string
    {
        $has = fn(string $needle) => in_array($needle, $formats, true);
        $hasSubstr = fn(string $needle) => (bool)array_filter(
            $formats,
            fn(string $f) => str_contains($f, $needle),
        );

        // ── Vinyl sub-types (check before generic "vinyl") ───────────────────
        if ($has('shellac') || $hasSubstr('shellac')) {
            return 'Shellac';
        }
        if ($has('flexi-disc') || $has('flexi disc')) {
            return 'Flexi-disc';
        }
        if ($has('lathe cut')) {
            return 'Lathe Cut';
        }
        if ($has('picture disc')) {
            return 'Picture Disc';
        }
        // Specific sizes — only when vinyl is also present or as standalone token
        if ($has('7"') || $has('7\'\'') || $has('7-inch')) {
            return '7" Single';
        }
        if ($has('10"') || $has('10\'\'')) {
            return '10"';
        }
        if ($has('12"') || $has('12\'\'')) {
            return '12" Single';
        }
        if ($has('vinyl') || $has('lp')) {
            return 'Vinyl';
        }

        // ── Tape formats ─────────────────────────────────────────────────────
        if ($has('8-track') || $has('8 track') || $has('8track')) {
            return '8-Track';
        }
        if ($has('reel-to-reel') || $has('reel to reel') || $has('open reel')) {
            return 'Reel-to-Reel';
        }
        if ($has('dat')) {
            return 'DAT';
        }
        if ($has('dcc') || $has('digital compact cassette')) {
            return 'DCC';
        }
        if ($has('microcassette')) {
            return 'Microcassette';
        }
        if ($has('4-track') || $has('4 track')) {
            return '4-Track Cartridge';
        }
        if ($has('cassette')) {
            return 'Cassette';
        }

        // ── Optical disc formats ──────────────────────────────────────────────
        if ($has('sacd') || $has('sacd hybrid')) {
            return 'SACD';
        }
        if ($has('shm-cd') || $has('shm cd')) {
            return 'SHM-CD';
        }
        if ($has('hdcd')) {
            return 'HDCD';
        }
        if ($has('cd-r') || $has('cd r')) {
            return 'CD-R';
        }
        if ($has('blu-ray') || $has('blu ray') || $has('blu-ray audio')) {
            return 'Blu-ray Audio';
        }
        if ($has('dvd-audio') || $has('dvd audio')) {
            return 'DVD-Audio';
        }
        if ($has('cdv')) {
            return 'CDV';
        }
        if ($has('laserdisc') || $has('laser disc')) {
            return 'LaserDisc';
        }
        if ($has('cd')) {
            return 'CD';
        }

        // ── Other digital carriers ────────────────────────────────────────────
        if ($has('minidisc') || $has('mini disc')) {
            return 'MiniDisc';
        }

        return 'Vinyl'; // sensible default for music
    }
}
