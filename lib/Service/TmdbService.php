<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Exception\RecommendationUnavailableException;
use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

/**
 * Films from TMDB, in the requesting user's language where TMDB has it (see
 * EnrichmentLanguage).
 *
 * Search and suggestion lists ask TMDB for that language and show its titles
 * as TMDB gives them: TMDB falls back to a film's original title, not to
 * English, when it has no translation. A film's details — what enrichment
 * stores — are asked for in English, as they always were, and overlaid with
 * the user's translation where one exists, so a missing translation leaves
 * English text rather than another language's. Genres, studio and country
 * stay English: genre names are what the filters and suggestions match on.
 */
class TmdbService extends AbstractApiService
{
    private const API_BASE  = 'https://api.themoviedb.org/3';
    private const IMG_THUMB = 'https://image.tmdb.org/t/p/w185';
    private const IMG_FULL  = 'https://image.tmdb.org/t/p/w500';

    /** What every request asked for before languages, and still does for English. */
    private const ENGLISH = 'en-US';

    public function __construct(
        IClientService $clientService,
        ICredentialsManager $credentialsManager,
        LoggerInterface $logger,
        private readonly EnrichmentLanguage $language,
    ) {
        parent::__construct($clientService, $credentialsManager, $logger);
    }

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
            ['query' => $query, 'language' => $this->language->tmdb() ?? self::ENGLISH, 'page' => '1'],
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

        $localised = $this->language->tmdb();
        $body = $this->getJson(
            self::API_BASE . '/movie/' . rawurlencode($movieId),
            [
                'language'           => self::ENGLISH,
                'append_to_response' => $localised !== null ? 'credits,translations' : 'credits',
            ],
            ['Authorization' => 'Bearer ' . $token],
        );

        if (empty($body)) {
            return [];
        }

        $movie = $this->normaliseMovie($body);
        return $localised !== null ? self::localise($movie, $body, $localised) : $movie;
    }

    /**
     * Put the title and overview of the user's translation over a film's
     * English details.
     *
     * TMDB leaves a translation's title blank when it is the film's original
     * title, so a blank title in the translation that was found reads as the
     * original title — the title the film has in that language. With no
     * translation for the language at all, a film made in it keeps its
     * original title, and any other keeps its English one. An overview the
     * translation lacks stays English.
     *
     * @param array<string, mixed> $movie normaliseMovie()'s output
     * @param array<string, mixed> $body  The details response, with translations
     * @param string $code TMDB language-region code, e.g. "de-DE"
     * @return array<string, mixed>
     */
    public static function localise(array $movie, array $body, string $code): array
    {
        [$lang, $region] = explode('-', $code, 2);
        $original = trim((string)($body['original_title'] ?? ''));
        $translation = self::pickTranslation((array)($body['translations']['translations'] ?? []), $lang, $region);

        if ($translation === null) {
            if (($body['original_language'] ?? null) === $lang && $original !== '') {
                $movie['title'] = $original;
            }
            return $movie;
        }

        $data = (array)($translation['data'] ?? []);
        $title = trim((string)($data['title'] ?? ''));
        if ($title !== '') {
            $movie['title'] = $title;
        } elseif ($original !== '') {
            $movie['title'] = $original;
        }
        $overview = trim((string)($data['overview'] ?? ''));
        if ($overview !== '') {
            $movie['overview'] = $overview;
        }
        return $movie;
    }

    /**
     * The translation to use for a language: the one for the user's region,
     * else the language's own country's, else any in the language — a
     * Brazilian reader is better served by European Portuguese than by
     * English.
     *
     * @param array<int, mixed> $translations
     * @return array<string, mixed>|null
     */
    private static function pickTranslation(array $translations, string $lang, string $region): ?array
    {
        $inLanguage = array_values(array_filter(
            $translations,
            fn($t) => is_array($t) && ($t['iso_639_1'] ?? null) === $lang,
        ));
        foreach ([$region, EnrichmentLanguage::TMDB_PRIMARY_REGION[$lang] ?? null] as $wanted) {
            foreach ($inLanguage as $t) {
                if ($wanted !== null && ($t['iso_3166_1'] ?? null) === $wanted) {
                    return $t;
                }
            }
        }
        return $inLanguage[0] ?? null;
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
                    ['language' => $this->language->tmdb() ?? self::ENGLISH, 'page' => '1'],
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
