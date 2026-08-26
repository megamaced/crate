<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

class OpenLibraryService extends AbstractApiService
{
    private const SEARCH_URL = 'https://openlibrary.org/search.json';
    private const COVER_BASE = 'https://covers.openlibrary.org/b/';

    /**
     * Fields asked of search.json: everything normaliseDoc() reads, including
     * every cover identifier. The API returns exactly what is listed here, so
     * an omitted identifier is indistinguishable from a book that has no
     * cover — which is how a rail ends up as a row of grey boxes.
     */
    private const SEARCH_FIELDS = 'key,title,author_name,first_publish_year,'
        . 'cover_i,cover_edition_key,lending_edition_s,edition_key,publisher,isbn,subject';

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
            'fields' => self::SEARCH_FIELDS,
        ]);

        $docs = array_slice((array)($body['docs'] ?? []), 0, 10);
        return array_values(array_map(fn(array $d) => $this->normaliseDoc($d), $docs));
    }

    /**
     * Look a book up by ISBN through the search index.
     *
     * The Books API (getByIsbn) answers the same question, but its payload
     * carries no work key for a large part of the catalogue, and the work key
     * is what every later step needs: it is the enrichment id, the handle
     * getWork() reads the description and subjects from, and the exclusion key
     * for the read-alike rail. The search index always has one.
     *
     * @return array<string, mixed> Empty when the ISBN is unknown to Open Library
     */
    public function searchByIsbn(string $isbn): array
    {
        // The index stores ISBNs unpunctuated, and the field query would treat
        // anything else in the value as query syntax.
        $isbn = strtoupper((string)preg_replace('/[^0-9Xx]/', '', $isbn));
        if (!preg_match('/^(?:[0-9]{9}[0-9X]|[0-9]{13})$/', $isbn)) {
            return [];
        }

        $body = $this->getJson(self::SEARCH_URL, [
            'q'      => 'isbn:' . $isbn,
            'limit'  => '1',
            'fields' => self::SEARCH_FIELDS,
        ]);

        $doc = ((array)($body['docs'] ?? []))[0] ?? null;
        return is_array($doc) ? $this->normaliseDoc($doc) : [];
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
            'fields' => self::SEARCH_FIELDS,
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

        $coverId    = isset($body['covers'][0]) ? (int)$body['covers'][0] : 0;
        $artworkUrl = $coverId > 0 ? self::COVER_BASE . 'id/' . $coverId . '-L.jpg' : null;

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

        $publishers = (array)($d['publisher'] ?? []);
        $label      = !empty($publishers[0]) ? (string)$publishers[0] : null;

        $isbns   = (array)($d['isbn'] ?? []);
        $barcode = !empty($isbns[0]) ? (string)$isbns[0] : null;

        $subjects = array_slice((array)($d['subject'] ?? []), 0, 10);
        $genres   = $subjects ? implode(', ', $subjects) : null;

        return [
            'workKey'    => (string)($d['key'] ?? ''),
            'title'      => $d['title'] ?? '',
            'artist'     => $artist,
            'year'       => $year,
            'thumb'      => $this->coverUrl($d, 'M'),
            'artworkUrl' => $this->coverUrl($d, 'L'),
            'label'      => $label,
            'barcode'    => $barcode,
            'genres'     => $genres,
        ];
    }

    /**
     * Cover URL for a search doc at the requested size, or null when the doc
     * names no cover at all.
     *
     * Only part of the catalogue carries `cover_i`; the rest identifies its
     * cover by edition, and covers.openlibrary.org serves the same image under
     * /b/olid/ and /b/isbn/ as it does under /b/id/. Falling through whichever
     * identifiers a doc does have is the difference between a rail of covers
     * and a rail of grey boxes.
     *
     * @param array<string, mixed> $d
     */
    private function coverUrl(array $d, string $size): ?string
    {
        $coverId = isset($d['cover_i']) ? (int)$d['cover_i'] : 0;
        if ($coverId > 0) {
            return self::COVER_BASE . 'id/' . $coverId . '-' . $size . '.jpg';
        }

        // cover_edition_key and lending_edition_s are the editions Open Library
        // itself picks to represent the work, so they carry a cover far more
        // often than an arbitrary member of edition_key.
        $olid = '';
        foreach (['cover_edition_key', 'lending_edition_s'] as $field) {
            $olid = trim((string)($d[$field] ?? ''));
            if ($olid !== '') {
                break;
            }
        }
        if ($olid === '') {
            $editions = (array)($d['edition_key'] ?? []);
            $olid     = trim((string)($editions[0] ?? ''));
        }
        if ($olid !== '') {
            return self::COVER_BASE . 'olid/' . rawurlencode($olid) . '-' . $size . '.jpg';
        }

        $isbns = (array)($d['isbn'] ?? []);
        $isbn  = trim((string)($isbns[0] ?? ''));
        if ($isbn !== '') {
            return self::COVER_BASE . 'isbn/' . rawurlencode($isbn) . '-' . $size . '.jpg';
        }

        return null;
    }
}
