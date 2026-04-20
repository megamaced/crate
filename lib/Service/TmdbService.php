<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class TmdbService
{
    private const API_BASE  = 'https://api.themoviedb.org/3';
    private const IMG_THUMB = 'https://image.tmdb.org/t/p/w185';
    private const IMG_FULL  = 'https://image.tmdb.org/t/p/w500';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Search TMDB for movies by free-text query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->get($token, self::API_BASE . '/search/movie', [
            'query'    => $query,
            'language' => 'en-US',
            'page'     => '1',
        ]);

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
        $token = $this->getToken($userId);
        if ($token === '') {
            return [];
        }

        $body = $this->get($token, self::API_BASE . '/movie/' . rawurlencode($movieId), [
            'language'            => 'en-US',
            'append_to_response' => 'credits',
        ]);

        if (empty($body)) {
            return [];
        }

        return $this->normaliseMovie($body);
    }

    // -------------------------------------------------------------------------

    private function getToken(string $userId): string
    {
        return (string)($this->credentialsManager->retrieve($userId, 'crate/tmdb_token') ?? '');
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function get(string $token, string $url, array $query = []): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 10,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->warning('TMDB API error for {url}: {msg}', [
                'url' => strtok($url, '?') ?: $url,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
            return [];
        }
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

        // Director from credits
        $director   = null;
        $directorId = null;
        foreach ((array)($r['credits']['crew'] ?? []) as $person) {
            if (($person['job'] ?? '') === 'Director') {
                $director   = $person['name'] ?? null;
                $directorId = isset($person['id']) ? (string)$person['id'] : null;
                break;
            }
        }

        // Genres
        $genreNames = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));
        $genres     = $genreNames ? implode(', ', array_filter($genreNames)) : null;

        // Production company (studio)
        $companies = (array)($r['production_companies'] ?? []);
        $studio    = !empty($companies[0]['name']) ? $companies[0]['name'] : null;

        // Production country
        $countries = (array)($r['production_countries'] ?? []);
        $country   = !empty($countries[0]['name']) ? $countries[0]['name'] : null;

        // Artwork
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
