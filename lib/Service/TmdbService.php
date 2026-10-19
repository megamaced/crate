<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Exception\RecommendationUnavailableException;

class TmdbService extends AbstractApiService
{
    private const API_BASE  = 'https://api.themoviedb.org/3';
    private const IMG_THUMB = 'https://image.tmdb.org/t/p/w185';
    private const IMG_FULL  = 'https://image.tmdb.org/t/p/w500';

    protected function serviceName(): string
    {
        return 'TMDB';
    }

    protected function credentialKey(): string
    {
        return 'crate/tmdb_token';
    }

    /**
     * Search TMDB for movies by free-text query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        $token = $this->getCredential($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->getJson(
            self::API_BASE . '/search/movie',
            ['query' => $query, 'language' => 'en-US', 'page' => '1'],
            ['Authorization' => 'Bearer ' . $token],
        );

        $results = array_slice((array)($body['results'] ?? []), 0, 10);
        return array_values(array_map(fn(array $r) => $this->normaliseResult($r), $results));
    }

    /**
     * Fetch full movie details (including director from credits).
     *
     * @return array<string, mixed>
     */
    public function getMovie(string $userId, string $movieId): array
    {
        $token = $this->getCredential($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->getJson(
            self::API_BASE . '/movie/' . rawurlencode($movieId),
            ['language' => 'en-US', 'append_to_response' => 'credits'],
            ['Authorization' => 'Bearer ' . $token],
        );

        if (empty($body)) {
            return [];
        }

        return $this->normaliseMovie($body);
    }

    /**
     * Films similar to a movie, in the same shape as search() so the
     * add-from-external path can consume either.
     *
     * Prefers /recommendations (TMDB's own blend, which factors in what users
     * actually watch together) and falls back to /similar, which TMDB
     * documents as genre + plot-keyword matching only.
     *
     * An empty list is TMDB's answer. When there is no answer — no token, or
     * a request that failed with nothing else to show — this throws instead,
     * so the caller does not keep the silence as if it were one.
     *
     * @return array<int, array<string, mixed>>
     * @throws RecommendationUnavailableException without a token
     * @throws \Exception when a lookup failed and none found anything
     */
    public function similar(string $userId, string $movieId, int $limit = 8): array
    {
        $token = $this->getCredential($userId);
        if ($token === '') {
            throw new RecommendationUnavailableException('TMDB suggestions need an API token.');
        }

        $failure = null;
        foreach (['recommendations', 'similar'] as $endpoint) {
            $url = self::API_BASE . '/movie/' . rawurlencode($movieId) . '/' . $endpoint;
            try {
                $results = $this->fetchList(
                    $url,
                    'results',
                    ['language' => 'en-US', 'page' => '1'],
                    ['Authorization' => 'Bearer ' . $token],
                );
            } catch (\Exception $e) {
                $failure = $e;
                continue;
            }
            $results = array_slice($results, 0, $limit);
            if (!empty($results)) {
                return array_values(array_map(fn(array $r) => $this->normaliseResult($r), $results));
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
        return [];
    }

    /** @param array<string, mixed> $r */
    private function normaliseResult(array $r): array
    {
        $year = null;
        if (!empty($r['release_date'])) {
            $year = (int)substr((string)$r['release_date'], 0, 4) ?: null;
        }

        return [
            'tmdbId' => (string)($r['id'] ?? ''),
            'title'  => $r['title'] ?? '',
            'year'   => $year,
            'thumb'  => isset($r['poster_path']) ? self::IMG_THUMB . $r['poster_path'] : null,
        ];
    }

    /** @param array<string, mixed> $r */
    private function normaliseMovie(array $r): array
    {
        $year = null;
        if (!empty($r['release_date'])) {
            $year = (int)substr((string)$r['release_date'], 0, 4) ?: null;
        }

        $director   = null;
        $directorId = null;
        foreach ((array)($r['credits']['crew'] ?? []) as $person) {
            if (($person['job'] ?? '') === 'Director') {
                $director   = $person['name'] ?? null;
                $directorId = isset($person['id']) ? (string)$person['id'] : null;
                break;
            }
        }

        $genreNames = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));
        $genres     = $genreNames ? implode(', ', array_filter($genreNames)) : null;

        $companies = (array)($r['production_companies'] ?? []);
        $studio    = !empty($companies[0]['name']) ? $companies[0]['name'] : null;

        $countries = (array)($r['production_countries'] ?? []);
        $country   = !empty($countries[0]['name']) ? $countries[0]['name'] : null;

        $artworkUrl = isset($r['poster_path']) ? self::IMG_FULL . $r['poster_path'] : null;
        $thumb      = isset($r['poster_path']) ? self::IMG_THUMB . $r['poster_path'] : null;

        return [
            'tmdbId'     => (string)($r['id'] ?? ''),
            'title'      => $r['title'] ?? '',
            'artist'     => $director,
            'directorId' => $directorId,
            'year'       => $year,
            'genres'     => $genres,
            'label'      => $studio,
            'country'    => $country,
            'overview'   => trim((string)($r['overview'] ?? '')) ?: null,
            'artworkUrl' => $artworkUrl,
            'thumb'      => $thumb,
        ];
    }
}
