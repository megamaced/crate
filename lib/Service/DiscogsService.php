<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class DiscogsService
{
    private const API_BASE = 'https://api.discogs.com';
    private const USER_AGENT = 'CrateNextcloudApp/0.1 +https://gitea.macecloud.co.uk/macebox/crate';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly LoggerInterface $logger,
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
        return (string) ($this->credentialsManager->retrieve($userId, 'crate/discogs_token') ?? '');
    }

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
            // Strip query parameters before logging to avoid leaking tokens
            // or request details.
            $logUrl = strtok($url, '?') ?: $url;
            $this->logger->warning('Discogs API error for {url}: {msg}', [
                'url' => $logUrl,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
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
     * Ordered lookup table for mapping Discogs format tokens to canonical names.
     * Order matters — more specific formats must appear before generic ones
     * (e.g. "flexi-disc" before "vinyl").
     */
    private const FORMAT_MAP = [
        // Vinyl sub-types (before generic 'vinyl')
        'flexi-disc'               => 'Flexi-disc',
        'flexi disc'               => 'Flexi-disc',
        'lathe cut'                => 'Lathe Cut',
        'picture disc'             => 'Picture Disc',
        '7"'                       => '7" Single',
        "7''"                      => '7" Single',
        '7-inch'                   => '7" Single',
        '10"'                      => '10"',
        "10''"                     => '10"',
        '12"'                      => '12" Single',
        "12''"                     => '12" Single',
        'vinyl'                    => 'Vinyl',
        'lp'                       => 'Vinyl',
        // Tape formats
        '8-track'                  => '8-Track',
        '8 track'                  => '8-Track',
        '8track'                   => '8-Track',
        'reel-to-reel'             => 'Reel-to-Reel',
        'reel to reel'             => 'Reel-to-Reel',
        'open reel'                => 'Reel-to-Reel',
        'dat'                      => 'DAT',
        'dcc'                      => 'DCC',
        'digital compact cassette' => 'DCC',
        'microcassette'            => 'Microcassette',
        '4-track'                  => '4-Track Cartridge',
        '4 track'                  => '4-Track Cartridge',
        'cassette'                 => 'Cassette',
        // Optical disc formats
        'sacd'                     => 'SACD',
        'sacd hybrid'              => 'SACD',
        'shm-cd'                   => 'SHM-CD',
        'shm cd'                   => 'SHM-CD',
        'hdcd'                     => 'HDCD',
        'cd-r'                     => 'CD-R',
        'cd r'                     => 'CD-R',
        'blu-ray'                  => 'Blu-ray Audio',
        'blu ray'                  => 'Blu-ray Audio',
        'blu-ray audio'            => 'Blu-ray Audio',
        'dvd-audio'                => 'DVD-Audio',
        'dvd audio'                => 'DVD-Audio',
        'cdv'                      => 'CDV',
        'laserdisc'                => 'LaserDisc',
        'laser disc'               => 'LaserDisc',
        'cd'                       => 'CD',
        // Other digital carriers
        'minidisc'                 => 'MiniDisc',
        'mini disc'                => 'MiniDisc',
    ];

    /**
     * Map an array of Discogs format tokens (name + descriptions, lowercased)
     * to our canonical format string.
     *
     * @param string[] $formats
     */
    private function mapFormat(array $formats): string
    {
        // Shellac: needs substring match (Discogs sometimes uses compound tokens)
        foreach ($formats as $f) {
            if (str_contains($f, 'shellac')) {
                return 'Shellac';
            }
        }

        // Exact-match lookup — first match wins (order in FORMAT_MAP is significant)
        foreach (self::FORMAT_MAP as $token => $canonical) {
            if (in_array($token, $formats, true)) {
                return $canonical;
            }
        }

        return 'Vinyl'; // sensible default for music
    }
}
