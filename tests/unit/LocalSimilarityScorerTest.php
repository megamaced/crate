<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Service\LocalSimilarityScorer;
use PHPUnit\Framework\TestCase;

/**
 * Ranking rules for the "More from your crate" rail. These weights are
 * mirrored in crate-android's `LocalSimilarity.kt`, so a deliberate change
 * here needs the same change there.
 */
class LocalSimilarityScorerTest extends TestCase
{
    private LocalSimilarityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new LocalSimilarityScorer();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function item(int $id, array $fields = []): MediaItem
    {
        $item = new MediaItem();
        $item->setId($id);
        $item->setTitle($fields['title'] ?? 'Title ' . $id);
        $item->setArtist($fields['artist'] ?? 'Artist ' . $id);
        $item->setFormat($fields['format'] ?? '');
        $item->setStatus($fields['status'] ?? 'owned');
        $item->setYear($fields['year'] ?? null);
        $item->setGenres($fields['genres'] ?? null);
        $item->setLabel($fields['label'] ?? null);
        return $item;
    }

    public function testSameArtistOutranksGenreOverlap(): void
    {
        $subject   = $this->item(1, ['artist' => 'Slint', 'genres' => 'Rock, Post-Rock, Math Rock']);
        $sameBand  = $this->item(2, ['artist' => 'Slint']);
        $sameGenre = $this->item(3, ['artist' => 'Shellac', 'genres' => 'Rock, Post-Rock, Math Rock']);

        self::assertGreaterThan(
            $this->scorer->score($subject, $sameGenre),
            $this->scorer->score($subject, $sameBand),
        );
    }

    public function testGenreOverlapContributionIsCapped(): void
    {
        $manyShared = implode(', ', ['Rock', 'Punk', 'Indie', 'Lo-Fi', 'Noise', 'Emo']);
        $subject = $this->item(1, ['artist' => 'A', 'genres' => $manyShared]);
        $other   = $this->item(2, ['artist' => 'B', 'genres' => $manyShared]);

        // Six shared tokens would be 60 uncapped; capped at 30 (+1 owned) it
        // stays below a same-artist match, so genre spam can't outrank one.
        self::assertSame(30 + 1, $this->scorer->score($subject, $other));
    }

    public function testUnrelatedItemsScoreZeroAndAreOmitted(): void
    {
        $subject = $this->item(1, ['artist' => 'Slint', 'genres' => 'Post-Rock']);
        $other   = $this->item(2, ['artist' => 'Dolly Parton', 'genres' => 'Country']);

        self::assertSame(0, $this->scorer->score($subject, $other));
        self::assertSame([], $this->scorer->rank($subject, [$other]));
    }

    public function testGenreTokensAreMatchedCaseAndSpaceInsensitively(): void
    {
        // Discogs writes "Post-Rock", Open Library writes subjects in prose case.
        $subject = $this->item(1, ['artist' => 'A', 'genres' => 'Post-Rock, Shoegaze']);
        $other   = $this->item(2, ['artist' => 'B', 'genres' => '  post-rock ,  SHOEGAZE ']);

        self::assertSame(20 + 1, $this->scorer->score($subject, $other));
    }

    public function testItemIsNeverRecommendedToItself(): void
    {
        $subject = $this->item(1, ['artist' => 'Slint', 'genres' => 'Post-Rock']);

        self::assertSame([], $this->scorer->rank($subject, [$subject]));
    }

    public function testFormatAloneNeverPromotesAnItem(): void
    {
        // Everything in a record collection is "Vinyl"; on its own that says nothing.
        $subject = $this->item(1, ['artist' => 'A', 'format' => 'Vinyl']);
        $other   = $this->item(2, ['artist' => 'B', 'format' => 'Vinyl']);

        self::assertSame(0, $this->scorer->score($subject, $other));
    }

    public function testEmptyArtistDoesNotCountAsAMatch(): void
    {
        $subject = $this->item(1, ['artist' => '', 'genres' => 'Jazz']);
        $other   = $this->item(2, ['artist' => '', 'genres' => 'Jazz']);

        // Genre overlap only — the two blank artists must not add 50.
        self::assertSame(10 + 1, $this->scorer->score($subject, $other));
    }

    public function testUnenrichedItemsStillMatchOnArtist(): void
    {
        // No genres, label or year: the state of a hand-typed, unenriched item.
        $subject = $this->item(1, ['artist' => 'Slint']);
        $other   = $this->item(2, ['artist' => 'Slint']);

        self::assertSame([2], array_map(
            static fn(MediaItem $i): int => $i->getId(),
            $this->scorer->rank($subject, [$other]),
        ));
    }

    public function testYearProximityIsBanded(): void
    {
        $subject = $this->item(1, ['artist' => 'A', 'genres' => 'Jazz', 'year' => 1960]);

        $close = $this->item(2, ['artist' => 'B', 'genres' => 'Jazz', 'year' => 1963]);
        $near  = $this->item(3, ['artist' => 'C', 'genres' => 'Jazz', 'year' => 1969]);
        $far   = $this->item(4, ['artist' => 'D', 'genres' => 'Jazz', 'year' => 1999]);

        self::assertSame(10 + 4 + 1, $this->scorer->score($subject, $close));
        self::assertSame(10 + 2 + 1, $this->scorer->score($subject, $near));
        self::assertSame(10 + 1, $this->scorer->score($subject, $far));
    }

    public function testOwnedItemsEdgeOutWishlistItemsAtEqualScore(): void
    {
        $subject = $this->item(1, ['artist' => 'A', 'genres' => 'Jazz']);
        $owned   = $this->item(2, ['artist' => 'B', 'genres' => 'Jazz', 'status' => 'owned']);
        $wanted  = $this->item(3, ['artist' => 'C', 'genres' => 'Jazz', 'status' => 'wanted']);

        self::assertGreaterThan(
            $this->scorer->score($subject, $wanted),
            $this->scorer->score($subject, $owned),
        );
    }

    public function testRankRespectsTheLimit(): void
    {
        $subject = $this->item(1, ['artist' => 'Slint']);
        $candidates = [];
        for ($id = 2; $id <= 12; $id++) {
            $candidates[] = $this->item($id, ['artist' => 'Slint']);
        }

        self::assertCount(6, $this->scorer->rank($subject, $candidates));
        self::assertCount(3, $this->scorer->rank($subject, $candidates, 3));
    }

    public function testRankOrderIsStableForEquallyScoredItems(): void
    {
        $subject = $this->item(1, ['artist' => 'Slint']);
        $a = $this->item(2, ['artist' => 'Slint', 'year' => 1991]);
        $b = $this->item(3, ['artist' => 'Slint', 'year' => 1989]);

        $ids = static fn(array $ranked): array => array_map(
            static fn(MediaItem $i): int => $i->getId(),
            $ranked,
        );

        // Newest first, and the same order whichever way the candidates arrive.
        self::assertSame([2, 3], $ids($this->scorer->rank($subject, [$a, $b])));
        self::assertSame([2, 3], $ids($this->scorer->rank($subject, [$b, $a])));
    }
}
