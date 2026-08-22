<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

class OpenLibraryService extends AbstractApiService
{
    private const SEARCH_URL = 'https://openlibrary.org/search.json';
    private const COVER_URL  = 'https://covers.openlibrary.org/b/id/';

    protected function serviceName(): string
    {
        return 'Open Library';
    }

    /**
     * Search Open Library by free-text query.
     * No API key required.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        $body = $this->getJson(self::SEARCH_URL, [
            'q'      => $query,
            'limit'  => '10',
            'fields' => 'key,title,author_name,first_publish_year,cover_i,publisher,isbn,subject',
        ]);

        $docs = array_slice((array)($body['docs'] ?? []), 0, 10);
        return array_values(array_map(fn(array $d) => $this->normaliseDoc($d), $docs));
    }

    /**
     * Read-alikes for a book, in the same shape as search() so the
     * add-from-external path can consume either.
     *
     * Open Library has no read-alike endpoint, so this stands in for one:
     * search the work's own subjects, ranked by how many Open Library users
     * have the book on a reading log. Subjects are far more specific than
     * genres ("Cyberpunk", "Space warfare"), which is what makes this work.
     *
     * @param string|null $subjects   Stored `genres` value (comma-separated Open Library subjects)
     * @param string|null $excludeKey Work key of the item being viewed, so it can't recommend itself
     * @return array<int, array<string, mixed>>
     */
    public function similarBySubject(?string $subjects, ?string $excludeKey = null, int $limit = 8): array
    {
        $subject = $this->firstUsableSubject($subjects);
        if ($subject === null) {
            return [];
        }

        $body = $this->getJson(self::SEARCH_URL, [
            // Quoted so multi-word subjects match as a phrase.
            'q'      => 'subject:"' . $subject . '"',
            'sort'   => 'readinglog',
            'limit'  => (string)($limit + 1),
            'fields' => 'key,title,author_name,first_publish_year,cover_i,publisher,isbn,subject',
        ]);

        $out = [];
        foreach ((array)($body['docs'] ?? []) as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $key = (string)($doc['key'] ?? '');
            if ($key === '' || ($excludeKey !== null && $key === $excludeKey)) {
                continue;
            }
            $out[] = $this->normaliseDoc($doc);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Pick the subject to search on. Very broad subjects make for useless
     * read-alikes ("Fiction" matches most of the catalogue), so those are
     * skipped in favour of the first specific one.
     */
    private function firstUsableSubject(?string $subjects): ?string
    {
        if ($subjects === null || trim($subjects) === '') {
            return null;
        }

        $tooBroad = [
            'fiction', 'nonfiction', 'non-fiction', 'literature', 'general',
            'english language', 'large type books', 'accessible book',
        ];

        $fallback = null;
        foreach (explode(',', $subjects) as $raw) {
            $subject = trim($raw);
            // Quotes would break out of the phrase query.
            $subject = str_replace(['"', '\\'], '', $subject);
            if ($subject === '') {
                continue;
            }
            $fallback ??= $subject;
            if (!in_array(mb_strtolower($subject), $tooBroad, true)) {
                return $subject;
            }
        }

        return $fallback;
    }

    /**
     * Fetch full work details.
     * workId is the Open Library key, e.g. "/works/OL12345W" or just "OL12345W".
     *
     * @return array<string, mixed>
     */
    public function getWork(string $workId): array
    {
        // Strip a leading "/works/" if present, then validate against the
        // canonical Open Library work-key shape. This blocks URL-shape
        // injection — the caller-supplied id is concatenated into the
        // openlibrary.org URL, so we must not let arbitrary paths through.
        $bare = preg_replace('#^/works/#', '', $workId);
        if (!preg_match('/^OL[0-9]+W$/', (string)$bare)) {
            return [];
        }
        $workId = '/works/' . $bare;

        $body = $this->getJson('https://openlibrary.org' . $workId . '.json');
        if (empty($body)) {
            return [];
        }

        // Also fetch author info for the first author
        $authorBio  = null;
        $authorKey  = null;
        $authorKeys = (array)($body['authors'] ?? []);
        if (!empty($authorKeys[0]['author']['key'])) {
            $authorKey = (string)$authorKeys[0]['author']['key'];
            // The key comes from Open Library's response but is concatenated
            // into the URL, so apply the same shape guard as $workId rather
            // than trusting upstream blindly.
            if (preg_match('#^/authors/OL[0-9]+A$#', $authorKey)) {
                $authorBody = $this->getJson('https://openlibrary.org' . $authorKey . '.json');
            } else {
                $authorKey  = null;
                $authorBody = [];
            }
            if (!empty($authorBody)) {
                $bio = $authorBody['bio'] ?? null;
                if (is_array($bio)) {
                    $bio = $bio['value'] ?? null;
                }
                $authorBio = trim((string)($bio ?? '')) ?: null;
            }
        }

        $desc = $body['description'] ?? null;
        if (is_array($desc)) {
            $desc = $desc['value'] ?? null;
        }

        $subjects = array_slice((array)($body['subjects'] ?? []), 0, 10);
        $genres   = $subjects ? implode(', ', $subjects) : null;

        $coverId    = isset($body['covers'][0]) ? (int)$body['covers'][0] : null;
        $artworkUrl = $coverId ? self::COVER_URL . $coverId . '-L.jpg' : null;

        return [
            'workKey'    => $workId,
            'genres'     => $genres,
            'overview'   => trim((string)($desc ?? '')) ?: null,
            'artworkUrl' => $artworkUrl,
            'authorKey'  => $authorKey,
            'authorBio'  => $authorBio,
        ];
    }

    /**
     * Look up a book by ISBN via the Open Library Books API.
     * Returns the same normalised shape as normaliseDoc().
     *
     * @return array<string, mixed>
     */
    public function getByIsbn(string $isbn): array
    {
        $isbn   = preg_replace('/[^0-9Xx]/', '', $isbn);
        $bibKey = 'ISBN:' . strtoupper($isbn);

        $body = $this->getJson('https://openlibrary.org/api/books', [
            'bibkeys' => $bibKey,
            'format'  => 'json',
            'jscmd'   => 'data',
        ]);

        $data = $body[$bibKey] ?? null;
        if (empty($data) || !is_array($data)) {
            return [];
        }

        $authors = (array)($data['authors'] ?? []);
        $artist  = !empty($authors[0]['name']) ? (string)$authors[0]['name'] : null;

        $year = null;
        if (!empty($data['publish_date'])) {
            if (preg_match('/\d{4}/', (string)$data['publish_date'], $m)) {
                $year = (int)$m[0];
            }
        }

        $publishers = (array)($data['publishers'] ?? []);
        $label      = !empty($publishers[0]['name']) ? (string)$publishers[0]['name'] : null;

        $artworkUrl = $data['cover']['large']  ?? ($data['cover']['medium'] ?? null);
        $thumb      = $data['cover']['medium'] ?? null;

        $subjects = array_slice(
            array_map(
                fn($s) => isset($s['name']) ? (string)$s['name'] : null,
                (array)($data['subjects'] ?? []),
            ),
            0,
            10,
        );
        $subjects = array_values(array_filter($subjects));
        $genres   = $subjects ? implode(', ', $subjects) : null;

        $works   = (array)($data['works'] ?? []);
        $workKey = !empty($works[0]['key']) ? (string)$works[0]['key'] : '';

        return [
            'workKey'    => $workKey,
            'title'      => (string)($data['title'] ?? ''),
            'artist'     => $artist,
            'year'       => $year,
            'thumb'      => $thumb,
            'artworkUrl' => $artworkUrl,
            'label'      => $label,
            'barcode'    => $isbn,
            'genres'     => $genres,
        ];
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
