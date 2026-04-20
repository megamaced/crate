<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class RawgService
{
    private const API_BASE = 'https://api.rawg.io/api';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Search RAWG for games by free-text query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        $key = $this->getKey($userId);
        if ($key === '') {
            return [];
        }

        $body = $this->get(self::API_BASE . '/games', [
            'key'       => $key,
            'search'    => $query,
            'page_size' => '10',
        ]);

        $results = array_slice((array)($body['results'] ?? []), 0, 10);
        return array_values(array_map(fn(array $r) => $this->normaliseResult($r), $results));
    }

    /**
     * Fetch full game details from RAWG /games/{id}.
     *
     * @return array<string, mixed>
     */
    public function getGame(string $userId, string $gameId): array
    {
        $key = $this->getKey($userId);
        if ($key === '') {
            return [];
        }

        $body = $this->get(self::API_BASE . '/games/' . rawurlencode($gameId), ['key' => $key]);
        if (empty($body)) {
            return [];
        }

        return $this->normaliseGame($body);
    }

    // -------------------------------------------------------------------------

    private function getKey(string $userId): string
    {
        return (string)($this->credentialsManager->retrieve($userId, 'crate/rawg_key') ?? '');
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        $options = [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 10,
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }

        try {
            $response = $this->clientService->newClient()->get($url, $options);
            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->warning('RAWG API error for {url}: {msg}', [
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
        ];
    }

    /** @param array<string, mixed> $r */
    private function normaliseGame(array $r): array
    {
        $year = null;
        if (!empty($r['released'])) {
            $year = (int)substr((string)$r['released'], 0, 4) ?: null;
        }

        // Developer (first studio)
        $devs      = (array)($r['developers'] ?? []);
        $developer = !empty($devs[0]['name']) ? (string)$devs[0]['name'] : null;

        // Publisher
        $pubs      = (array)($r['publishers'] ?? []);
        $publisher = !empty($pubs[0]['name']) ? (string)$pubs[0]['name'] : null;

        // Genres
        $genreNames = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));
        $genres     = $genreNames ? implode(', ', array_filter($genreNames)) : null;

        // Description (strip HTML tags)
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
        ];
    }
}
