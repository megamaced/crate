<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;

/**
 * Ranks a user's own items by how much they resemble a given item — the
 * "More from your crate" rail.
 *
 * Deliberately free of I/O so the ranking rules are unit-testable and so the
 * Android client can reimplement the same rules over its Room cache, which is
 * what lets that rail work with no connection. Keep the weights below in sync
 * with `LocalSimilarity.kt` in crate-android.
 *
 * Candidates are drawn from the same category only: an album and a boardgame
 * sharing the token "fantasy" is a coincidence, not a recommendation.
 */
class LocalSimilarityScorer
{
    /** Same artist / author / director / developer — by far the strongest signal. */
    private const WEIGHT_SAME_ARTIST = 50;
    /** Per shared genre / style / subject token. */
    private const WEIGHT_GENRE_TOKEN = 10;
    /** Cap on the genre contribution, so a long subject list can't outrank an artist match. */
    private const MAX_GENRE_SCORE = 30;
    /** Same label / publisher / studio. */
    private const WEIGHT_SAME_LABEL = 6;
    private const WEIGHT_YEAR_CLOSE = 4;
    private const WEIGHT_YEAR_NEAR  = 2;
    private const WEIGHT_SAME_FORMAT = 2;
    /** Nudge owned items above wishlist ones at equal score — the rail is mostly about what you have. */
    private const WEIGHT_OWNED = 1;

    /**
     * @param MediaItem[] $candidates Same-category items belonging to the user, may include $item itself
     * @return list<MediaItem> Best matches first, capped at $limit, unrelated items omitted
     */
    public function rank(MediaItem $item, array $candidates, int $limit = 6): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            if ($candidate->getId() === $item->getId()) {
                continue;
            }
            $score = $this->score($item, $candidate);
            if ($score <= 0) {
                // No shared signal at all — padding the rail with arbitrary
                // items would make the whole feature look broken.
                continue;
            }
            $scored[] = ['score' => $score, 'item' => $candidate];
        }

        // Score desc, then newest, then id desc — a total order, so the rail
        // doesn't reshuffle between requests.
        usort($scored, function (array $a, array $b): int {
            return [$b['score'], $b['item']->getYear() ?? 0, $b['item']->getId()]
                <=> [$a['score'], $a['item']->getYear() ?? 0, $a['item']->getId()];
        });

        return array_map(
            static fn(array $row): MediaItem => $row['item'],
            array_slice($scored, 0, $limit),
        );
    }

    /** Similarity score between two items; 0 means "nothing in common". */
    public function score(MediaItem $item, MediaItem $candidate): int
    {
        $score = 0;

        $artist = $this->normalise($item->getArtist());
        if ($artist !== '' && $artist === $this->normalise($candidate->getArtist())) {
            $score += self::WEIGHT_SAME_ARTIST;
        }

        $shared = array_intersect(
            $this->tokens($item->getGenres()),
            $this->tokens($candidate->getGenres()),
        );
        if (!empty($shared)) {
            $score += min(count($shared) * self::WEIGHT_GENRE_TOKEN, self::MAX_GENRE_SCORE);
        }

        $label = $this->normalise($item->getLabel());
        if ($label !== '' && $label === $this->normalise($candidate->getLabel())) {
            $score += self::WEIGHT_SAME_LABEL;
        }

        $year = $item->getYear();
        $candidateYear = $candidate->getYear();
        if ($year !== null && $candidateYear !== null) {
            $gap = abs($year - $candidateYear);
            if ($gap <= 5) {
                $score += self::WEIGHT_YEAR_CLOSE;
            } elseif ($gap <= 10) {
                $score += self::WEIGHT_YEAR_NEAR;
            }
        }

        // Only meaningful alongside another signal, so it never promotes an
        // item on its own.
        if ($score > 0) {
            $format = $this->normalise($item->getFormat());
            if ($format !== '' && $format === $this->normalise($candidate->getFormat())) {
                $score += self::WEIGHT_SAME_FORMAT;
            }
            if ($candidate->getStatus() === CrateCategories::STATUS_OWNED) {
                $score += self::WEIGHT_OWNED;
            }
        }

        return $score;
    }

    /**
     * Split a stored `genres` value into comparable tokens. The column holds a
     * comma-separated blend of Discogs genres + styles, TMDB genres, RAWG
     * genres and Open Library subjects, so casing and spacing vary by source.
     *
     * @return list<string>
     */
    private function tokens(?string $genres): array
    {
        if ($genres === null || trim($genres) === '') {
            return [];
        }
        $parts = array_map(
            fn(string $p): string => $this->normalise($p),
            explode(',', $genres),
        );
        return array_values(array_unique(array_filter($parts, static fn(string $p): bool => $p !== '')));
    }

    private function normalise(?string $value): string
    {
        return trim(mb_strtolower($value ?? ''));
    }
}
