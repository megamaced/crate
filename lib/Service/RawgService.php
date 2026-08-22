<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

class RawgService extends AbstractApiService
{
    private const API_BASE = 'https://api.rawg.io/api';

    /**
     * RAWG genre names whose slug isn't just the lower-cased, hyphenated name.
     * The `genres` filter matches on slug, and a wrong slug is not an error —
     * it returns zero results, or is silently dropped from a multi-genre
     * filter — so guessing here fails quietly. Verified against /genres: this
     * is the only name in RAWG's fixed 19-genre vocabulary that differs.
     *
     * @var array<string, string>
     */
    private const GENRE_SLUGS = [
        'rpg' => 'role-playing-games-rpg',
    ];

    /**
     * Genres too broad to make a useful suggestion on their own — most games
     * carry at least one of them, so they'd return the same blockbusters
     * regardless of the item being viewed.
     *
     * @var list<string>
     */
    private const BROAD_GENRES = ['action', 'indie', 'adventure', 'casual'];

    /** Map RAWG platform names → Crate format values (physical media only). */
    private const PLATFORM_FORMAT_MAP = [
        'PlayStation 5'    => 'PS5',
        'PlayStation 4'    => 'PS4',
        'PlayStation 3'    => 'PS3',
        'PlayStation 2'    => 'PS2',
        'PlayStation'      => 'PS1',
        'PS Vita'          => 'PS Vita',
        'PSP'              => 'PSP',
        'Xbox Series S/X'  => 'Xbox Series X|S',
        'Xbox One'         => 'Xbox One',
        'Xbox 360'         => 'Xbox 360',
        'Xbox'             => 'Xbox',
        'Nintendo Switch'  => 'Switch',
        'Wii U'            => 'Wii U',
        'Wii'              => 'Wii',
        'GameCube'         => 'GameCube',
        'Nintendo 64'      => 'N64',
        'SNES'             => 'SNES',
        'NES'              => 'NES',
        'Nintendo 3DS'     => '3DS',
        'Nintendo DS'      => 'DS',
        'Game Boy Advance' => 'Game Boy Advance',
        'Game Boy Color'   => 'Game Boy Color',
        'Game Boy'         => 'Game Boy',
        'Dreamcast'        => 'Dreamcast',
        'SEGA Saturn'      => 'Saturn',
        'Genesis'          => 'Mega Drive / Genesis',
        'SEGA Master System' => 'Master System',
        'Game Gear'        => 'Game Gear',
        'SEGA 32X'         => 'Sega 32X',
        'Sega CD'          => 'Sega CD',
        'Neo Geo'          => 'Neo Geo AES',
        'Atari 2600'       => 'Atari 2600',
        'Atari 5200'       => 'Atari 5200',
        'Atari 7800'       => 'Atari 7800',
        'Atari Lynx'       => 'Atari Lynx',
        'Jaguar'           => 'Jaguar',
    ];

    protected function serviceName(): string
    {
        return 'RAWG';
    }

    protected function credentialKey(): string
    {
        return 'crate/rawg_key';
    }

    /**
     * Search RAWG for games by free-text query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        $key = $this->getCredential($userId);
        if ($key === '') {
            return [];
        }

        $body = $this->getJson(self::API_BASE . '/games', [
            'key'       => $key,
            'search'    => $query,
            'page_size' => '10',
        ]);

        $results = array_slice((array)($body['results'] ?? []), 0, 10);
        return array_values(array_map(fn(array $r) => $this->normaliseResult($r), $results));
    }

    /**
     * Games resembling one already in the collection, in the same shape as
     * search() so the add-from-external path can consume either.
     *
     * RAWG's own similar-games endpoint (/games/{id}/suggested) is a paid
     * Business-plan feature, so this composes two free endpoints instead:
     * franchise entries first (high precision — the same series is almost
     * always a good suggestion), then popular games sharing the item's
     * genres to fill the rail.
     *
     * @param string|null $genres Stored `genres` value (comma-separated RAWG genre names)
     * @return array<int, array<string, mixed>>
     */
    public function similar(string $userId, string $gameId, ?string $genres, int $limit = 8): array
    {
        $key = $this->getCredential($userId);
        if ($key === '') {
            return [];
        }

        $out  = [];
        $seen = [$gameId => true];

        $series = $this->getJson(
            self::API_BASE . '/games/' . rawurlencode($gameId) . '/game-series',
            ['key' => $key, 'page_size' => (string)$limit],
        );
        $this->collect((array)($series['results'] ?? []), $seen, $out, $limit);

        if (count($out) < $limit) {
            $slug = $this->genreSlug($genres);
            if ($slug !== null) {
                $byGenre = $this->getJson(self::API_BASE . '/games', [
                    'key' => $key,
                    'genres' => $slug,
                    // "-added" is RAWG's popularity proxy: how many users have
                    // the game in a library. Better than -rating, which floats
                    // obscure games with a handful of high scores.
                    'ordering' => '-added',
                    'page_size' => (string)($limit * 2),
                ]);
                $this->collect((array)($byGenre['results'] ?? []), $seen, $out, $limit);
            }
        }

        return $out;
    }

    /**
     * Normalise RAWG rows into $out, skipping ids already seen, until $limit.
     *
     * @param array<int, mixed>          $results
     * @param array<string, bool>        $seen
     * @param array<int, array<string, mixed>> $out
     */
    private function collect(array $results, array &$seen, array &$out, int $limit): void
    {
        foreach ($results as $result) {
            if (count($out) >= $limit) {
                return;
            }
            if (!is_array($result)) {
                continue;
            }
            $id = (string)($result['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $this->normaliseResult($result);
        }
    }

    /**
     * The single genre slug to search on, or null when the item has none.
     *
     * Only one: RAWG's `genres` filter is a union, not an intersection, so
     * passing two widens the pool rather than narrowing it (verified against
     * the live API — action=191k, rpg=62k, both=238k).
     *
     * Broad genres are skipped in favour of a specific one where the item has
     * both. Nearly every game is tagged "Action", so searching that returns
     * the same handful of blockbusters whatever the user is looking at.
     */
    private function genreSlug(?string $genres): ?string
    {
        if ($genres === null || trim($genres) === '') {
            return null;
        }

        $fallback = null;
        foreach (explode(',', $genres) as $raw) {
            $name = trim($raw);
            if ($name === '') {
                continue;
            }
            $slug = self::GENRE_SLUGS[mb_strtolower($name)] ?? $this->slugify($name);
            if ($slug === '') {
                continue;
            }
            $fallback ??= $slug;
            if (!in_array($slug, self::BROAD_GENRES, true)) {
                return $slug;
            }
        }

        return $fallback;
    }

    private function slugify(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)) ?? '';
        return trim($slug, '-');
    }

    /**
     * Fetch full game details from RAWG /games/{id}.
     *
     * @return array<string, mixed>
     */
    public function getGame(string $userId, string $gameId): array
    {
        $key = $this->getCredential($userId);
        if ($key === '') {
            return [];
        }

        $body = $this->getJson(self::API_BASE . '/games/' . rawurlencode($gameId), ['key' => $key]);
        if (empty($body)) {
            return [];
        }

        return $this->normaliseGame($body);
    }

    /** @param array<string, mixed> $r */
    private function normaliseResult(array $r): array
    {
        $year = null;
        if (!empty($r['released'])) {
            $year = (int)substr((string)$r['released'], 0, 4) ?: null;
        }

        $genres = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));

        return [
            'rawgId' => (string)($r['id'] ?? ''),
            'title'  => $r['name'] ?? '',
            'year'   => $year,
            'thumb'  => $r['background_image'] ?? null,
            'genres' => $genres ? implode(', ', array_filter($genres)) : null,
            'format' => $this->mapPlatform((array)($r['platforms'] ?? [])),
        ];
    }

    /** @param array<string, mixed> $r */
    private function normaliseGame(array $r): array
    {
        $year = null;
        if (!empty($r['released'])) {
            $year = (int)substr((string)$r['released'], 0, 4) ?: null;
        }

        $devs      = (array)($r['developers'] ?? []);
        $developer = !empty($devs[0]['name']) ? (string)$devs[0]['name'] : null;

        $pubs      = (array)($r['publishers'] ?? []);
        $publisher = !empty($pubs[0]['name']) ? (string)$pubs[0]['name'] : null;

        $genreNames = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));
        $genres     = $genreNames ? implode(', ', array_filter($genreNames)) : null;

        $desc = strip_tags((string)($r['description'] ?? ''));
        $desc = trim($desc) ?: null;

        return [
            'rawgId'     => (string)($r['id'] ?? ''),
            'title'      => $r['name'] ?? '',
            'artist'     => $developer,
            'year'       => $year,
            'label'      => $publisher,
            'genres'     => $genres,
            'overview'   => $desc,
            'artworkUrl' => $r['background_image'] ?? null,
            'thumb'      => $r['background_image'] ?? null,
            'format'     => $this->mapPlatform((array)($r['platforms'] ?? [])),
        ];
    }

    /**
     * Pick the first console/handheld platform from RAWG's platforms array
     * and map it to the corresponding Crate format value.
     *
     * @param array<int, mixed> $platforms  Each element has {platform: {name: string}}
     */
    private function mapPlatform(array $platforms): ?string
    {
        foreach ($platforms as $p) {
            $name = (string)(is_array($p['platform'] ?? null) ? ($p['platform']['name'] ?? '') : '');
            if (isset(self::PLATFORM_FORMAT_MAP[$name])) {
                return self::PLATFORM_FORMAT_MAP[$name];
            }
        }
        return null;
    }
}
