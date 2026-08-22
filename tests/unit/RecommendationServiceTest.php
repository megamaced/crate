<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Service\DiscogsService;
use OCA\Crate\Service\LocalSimilarityScorer;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\OpenLibraryService;
use OCA\Crate\Service\RawgService;
use OCA\Crate\Service\RecommendationService;
use OCA\Crate\Service\TmdbService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Preconditions for the online recommendation rail. Two of them matter enough
 * to pin down: the user has to have opted in, and the item has to carry an
 * enrichment id — that id is the provider's handle on the item, so without it
 * there is nothing to ask about.
 */
#[AllowMockObjectsWithoutExpectations]
class RecommendationServiceTest extends TestCase
{
    private MediaService&MockObject $mediaService;
    private MediaItemMapper&MockObject $mapper;
    private DiscogsService&MockObject $discogs;
    private TmdbService&MockObject $tmdb;
    private OpenLibraryService&MockObject $openLibrary;
    private RawgService&MockObject $rawg;
    private ICache&MockObject $cache;
    private IConfig&Stub $config;

    protected function setUp(): void
    {
        $this->mediaService = $this->createMock(MediaService::class);
        $this->mapper       = $this->createMock(MediaItemMapper::class);
        $this->discogs      = $this->createMock(DiscogsService::class);
        $this->tmdb         = $this->createMock(TmdbService::class);
        $this->openLibrary  = $this->createMock(OpenLibraryService::class);
        $this->rawg         = $this->createMock(RawgService::class);
        $this->cache        = $this->createMock(ICache::class);
        $this->config       = $this->createStub(IConfig::class);
    }

    private function service(): RecommendationService
    {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->cache);

        return new RecommendationService(
            $this->mediaService,
            $this->mapper,
            new LocalSimilarityScorer(),
            $this->discogs,
            $this->tmdb,
            $this->openLibrary,
            $this->rawg,
            $cacheFactory,
            $this->config,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function optIn(bool $enabled): void
    {
        // willReturnMap rather than with(): pins the exact setting key while
        // keeping $config a stub, so an unexpected key surfaces as a failure.
        $this->config->method('getUserValue')->willReturnMap([
            ['alice', 'crate', 'online_recommendations', 'no', $enabled ? 'yes' : 'no'],
        ]);
    }

    private function item(string $category, ?string $enrichmentId, ?string $genres = 'Rock'): MediaItem
    {
        $item = new MediaItem();
        $item->setId(1);
        $item->setTitle('Spiderland');
        $item->setArtist('Slint');
        $item->setCategory($category);
        $item->setGenres($genres);
        $item->setDiscogsId($enrichmentId);
        return $item;
    }

    public function testNoOnlineLookupWhenTheUserHasNotOptedIn(): void
    {
        $this->optIn(false);
        $this->discogs->expects(self::never())->method('similarByStyle');

        self::assertSame(
            [],
            $this->service()->online($this->item('music', '12345'), 'alice'),
        );
    }

    public function testNoOnlineLookupForAnUnenrichedItem(): void
    {
        // Opted in, but the item was never enriched, so there is no provider
        // id to ask about. Must not fall back to a title search.
        $this->optIn(true);
        $this->discogs->expects(self::never())->method('similarByStyle');

        self::assertSame(
            [],
            $this->service()->online($this->item('music', null), 'alice'),
        );
    }

    public function testEmptyStringEnrichmentIdCountsAsUnenriched(): void
    {
        $this->optIn(true);
        $this->discogs->expects(self::never())->method('similarByStyle');

        self::assertSame(
            [],
            $this->service()->online($this->item('music', ''), 'alice'),
        );
    }

    public function testOptedInEnrichedItemQueriesTheCategoryProvider(): void
    {
        $this->optIn(true);
        $this->cache->method('get')->willReturn(null);
        $this->discogs->expects(self::once())
            ->method('similarByStyle')
            ->with('alice', 'Rock', '12345', 8)
            ->willReturn([['discogsId' => '999', 'title' => 'Nirvana - Nevermind']]);

        $results = $this->service()->online($this->item('music', '12345'), 'alice');

        self::assertCount(1, $results);
        self::assertSame('999', $results[0]['discogsId']);
    }

    public function testEachCategoryReachesItsOwnProvider(): void
    {
        $this->optIn(true);
        $this->cache->method('get')->willReturn(null);

        $this->tmdb->expects(self::once())->method('similar')->willReturn([]);
        $this->openLibrary->expects(self::once())->method('similarBySubject')->willReturn([]);
        $this->rawg->expects(self::once())->method('similar')->willReturn([]);

        $service = $this->service();
        foreach (['film', 'book', 'game'] as $category) {
            $service->online($this->item($category, '12345'), 'alice');
        }
    }

    /**
     * ComicVine has no similarity data, and both workarounds fail against the
     * live API — its publisher filter and sort on /volumes are silently
     * ignored, and volumes don't return character credits. So comics get the
     * local row only, and must not reach any provider.
     */
    public function testComicsHaveNoOnlineRow(): void
    {
        $this->optIn(true);
        $this->cache->expects(self::never())->method('get');

        self::assertSame(
            [],
            $this->service()->online($this->item('comic', '12345'), 'alice'),
        );
    }

    public function testCachedResultsSkipTheUpstreamCall(): void
    {
        $this->optIn(true);
        $this->cache->method('get')->willReturn([['discogsId' => '777']]);
        $this->discogs->expects(self::never())->method('similarByStyle');

        $results = $this->service()->online($this->item('music', '12345'), 'alice');

        self::assertSame('777', $results[0]['discogsId']);
    }

    public function testEmptyResultsAreStillCached(): void
    {
        // Otherwise an item with no suggestions re-asks upstream on every view,
        // which is exactly what the provider rate limits punish.
        $this->optIn(true);
        $this->cache->method('get')->willReturn(null);
        $this->discogs->method('similarByStyle')->willReturn([]);
        $this->cache->expects(self::once())->method('set')->with(
            self::anything(),
            [],
            self::greaterThan(0),
        );

        self::assertSame([], $this->service()->online($this->item('music', '12345'), 'alice'));
    }

    public function testUpstreamFailureYieldsNoRailAndIsNotCached(): void
    {
        $this->optIn(true);
        $this->cache->method('get')->willReturn(null);
        $this->discogs->method('similarByStyle')
            ->willThrowException(new \RuntimeException('upstream on fire'));
        $this->cache->expects(self::never())->method('set');

        self::assertSame([], $this->service()->online($this->item('music', '12345'), 'alice'));
    }

    public function testLocalRailDrawsOnTheViewersOwnCollectionOnly(): void
    {
        $subject = $this->item('music', '12345');

        $mine = new MediaItem();
        $mine->setId(2);
        $mine->setTitle('Tweez');
        $mine->setArtist('Slint');
        $mine->setCategory('music');
        $mine->setStatus('owned');

        // Scoped to the viewer, and to the subject's own category.
        $this->mapper->expects(self::once())
            ->method('findAll')
            ->with('alice', 'music')
            ->willReturn([$subject, $mine]);

        $local = $this->service()->local($subject, 'alice');

        self::assertCount(1, $local);
        self::assertSame(2, $local[0]['id']);
    }

    public function testLocalRailNeedsNoEnrichmentOrOptIn(): void
    {
        $subject = $this->item('music', null, null);

        $mine = new MediaItem();
        $mine->setId(2);
        $mine->setTitle('Tweez');
        $mine->setArtist('Slint');
        $mine->setCategory('music');
        $mine->setStatus('owned');

        $this->mapper->method('findAll')->willReturn([$subject, $mine]);

        // Same artist alone is enough — this is what keeps the local rail
        // useful on a hand-typed collection.
        self::assertCount(1, $this->service()->local($subject, 'alice'));
    }
}
