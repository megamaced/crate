<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use OCP\Security\ICredentialsManager;
use Psr\Log\LoggerInterface;

class ComicVineService
{
    private const API_BASE = 'https://comicvine.gamespot.com/api';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly ICredentialsManager $credentialsManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Search ComicVine for comic volumes by free-text query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $userId, string $query): array
    {
        $key = $this->getKey($userId);
        if ($key === '') {
            return [];
        }

        $body = $this->get(self::API_BASE . '/search/', [
            'api_key'    => $key,
            'format'     => 'json',
            'resources'  => 'volume',
            'query'      => $query,
            'field_list' => 'id,name,start_year,publisher,genres,image',
            'limit'      => '10',
        ]);

        $results = array_slice((array)($body['results'] ?? []), 0, 10);
        return array_values(array_map(fn(array $r) => $this->normaliseResult($r), $results));
    }

    /**
     * Fetch full volume details from ComicVine /volume/4050-{id}/.
     *
     * @return array<string, mixed>
     */
    public function getVolume(string $userId, string $volumeId): array
    {
        $key = $this->getKey($userId);
        if ($key === '') {
            return [];
        }

        $body = $this->get(self::API_BASE . '/volume/4050-' . rawurlencode($volumeId) . '/', [
            'api_key'    => $key,
            'format'     => 'json',
            'field_list' => 'id,name,start_year,publisher,genres,image,description,count_of_issues',
        ]);

        $result = $body['results'] ?? [];
        if (empty($result)) {
            return [];
        }

        return $this->normaliseVolume((array)$result);
    }

    // -------------------------------------------------------------------------

    private function getKey(string $userId): string
    {
        return (string)($this->credentialsManager->retrieve($userId, 'crate/comicvine_key') ?? '');
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query = []): array
    {
        try {
            $response = $this->clientService->newClient()->get($url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
                'query'   => $query,
            ]);
            return json_decode($response->getBody(), true) ?? [];
        } catch (\Exception $e) {
            $this->logger->warning('ComicVine API error for {url}: {msg}', [
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
        $year = (isset($r['start_year']) && $r['start_year'] !== '') ? ((int)$r['start_year'] ?: null) : null;
        $publisher = is_array($r['publisher'] ?? null) ? ($r['publisher']['name'] ?? null) : null;
        $genres = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));

        return [
            'comicVineId' => (string)($r['id'] ?? ''),
            'title'       => $r['name'] ?? '',
            'year'        => $year,
            'label'       => $publisher,
            'genres'      => $genres ? implode(', ', array_filter($genres)) : null,
            'thumb'       => $r['image']['medium_url'] ?? $r['image']['small_url'] ?? null,
        ];
    }

    /** @param array<string, mixed> $r */
    private function normaliseVolume(array $r): array
    {
        $year = (isset($r['start_year']) && $r['start_year'] !== '') ? ((int)$r['start_year'] ?: null) : null;
        $publisher = is_array($r['publisher'] ?? null) ? ($r['publisher']['name'] ?? null) : null;
        $genres = array_map(fn(array $g) => $g['name'] ?? '', (array)($r['genres'] ?? []));
        $desc = trim(strip_tags((string)($r['description'] ?? ''))) ?: null;

        return [
            'comicVineId' => (string)($r['id'] ?? ''),
            'title'       => $r['name'] ?? '',
            'year'        => $year,
            'label'       => $publisher,
            'genres'      => $genres ? implode(', ', array_filter($genres)) : null,
            'overview'    => $desc,
            'artworkUrl'  => $r['image']['original_url'] ?? $r['image']['medium_url'] ?? null,
            'thumb'       => $r['image']['medium_url'] ?? null,
        ];
    }
}
