<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class OpenLibraryService
{
    private const SEARCH_URL = 'https://openlibrary.org/search.json';
    private const COVER_URL  = 'https://covers.openlibrary.org/b/id/';

    public function __construct(
        private readonly IClientService $clientService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Search Open Library by free-text query.
     * No API key required.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        $body = $this->get(self::SEARCH_URL, [
            'q'      => $query,
            'limit'  => '10',
            'fields' => 'key,title,author_name,first_publish_year,cover_i,publisher,isbn,subject',
        ]);

        $docs = array_slice((array)($body['docs'] ?? []), 0, 10);
        return array_values(array_map(fn(array $d) => $this->normaliseDoc($d), $docs));
    }

    /**
     * Fetch full work details.
     * workId is the Open Library key, e.g. "/works/OL12345W" or just "OL12345W".
     *
     * @return array<string, mixed>
     */
    public function getWork(string $workId): array
    {
        // Accept bare IDs like "OL12345W" as well as full "/works/OL12345W"
        if (!str_starts_with($workId, '/')) {
            $workId = '/works/' . $workId;
        }

        $body = $this->get('https://openlibrary.org' . $workId . '.json');
        if (empty($body)) {
            return [];
        }

        // Also fetch author info for the first author
        $authorBio  = null;
        $authorKey  = null;
        $authorKeys = (array)($body['authors'] ?? []);
        if (!empty($authorKeys[0]['author']['key'])) {
            $authorKey  = (string)$authorKeys[0]['author']['key'];
            $authorBody = $this->get('https://openlibrary.org' . $authorKey . '.json');
            if (!empty($authorBody)) {
                $bio = $authorBody['bio'] ?? null;
                if (is_array($bio)) {
                    $bio = $bio['value'] ?? null;
                }
                $authorBio = trim((string)($bio ?? '')) ?: null;
            }
        }

        // Description
        $desc = $body['description'] ?? null;
        if (is_array($desc)) {
            $desc = $desc['value'] ?? null;
        }

        // Subjects
        $subjects = array_slice((array)($body['subjects'] ?? []), 0, 10);
        $genres   = $subjects ? implode(', ', $subjects) : null;

        // Cover from first cover_id
        $coverId    = isset($body['covers'][0]) ? (int)$body['covers'][0] : null;
        $artworkUrl = $coverId ? self::COVER_URL . $coverId . '-L.jpg' : null;

        return [
            'workKey'   => $workId,
            'genres'    => $genres,
            'overview'  => trim((string)($desc ?? '')) ?: null,
            'artworkUrl' => $artworkUrl,
            'authorKey' => $authorKey,
            'authorBio' => $authorBio,
        ];
    }

    // -------------------------------------------------------------------------

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
            $this->logger->warning('Open Library API error for {url}: {msg}', [
                'url' => $url,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
            return [];
        }
    }

    /** @param array<string, mixed> $d */
    private function normaliseDoc(array $d): array
    {
        $authors = (array)($d['author_name'] ?? []);
        $artist  = !empty($authors[0]) ? (string)$authors[0] : null;

        $year = isset($d['first_publish_year']) ? (int)$d['first_publish_year'] : null;
        if ($year === 0) {
            $year = null;
        }

        $coverId = isset($d['cover_i']) ? (int)$d['cover_i'] : null;
        $thumb   = $coverId ? self::COVER_URL . $coverId . '-M.jpg' : null;

        $publishers = (array)($d['publisher'] ?? []);
        $label      = !empty($publishers[0]) ? (string)$publishers[0] : null;

        $isbns   = (array)($d['isbn'] ?? []);
        $barcode = !empty($isbns[0]) ? (string)$isbns[0] : null;

        $subjects = array_slice((array)($d['subject'] ?? []), 0, 10);
        $genres   = $subjects ? implode(', ', $subjects) : null;

        return [
            'workKey' => (string)($d['key'] ?? ''),
            'title'   => $d['title'] ?? '',
            'artist'  => $artist,
            'year'    => $year,
            'thumb'   => $thumb,
            'label'   => $label,
            'barcode' => $barcode,
            'genres'  => $genres,
        ];
    }
}
