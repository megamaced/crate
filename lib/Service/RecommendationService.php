<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Exception\DiscogsRateLimitException;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds the two recommendation rails shown on an item's detail view.
 *
 *  - "More from your crate": the viewer's own items, ranked locally. No
 *    network, always available, and thin but not useless on unenriched items
 *    (an artist match alone still counts).
 *  - "If you like this…": suggestions from the same upstream provider that
 *    enriched the item. Opt-in per user, and only possible at all once the
 *    item has an enrichment id — that id is the provider's handle on the
 *    item, and nothing but enrichment sets it.
 *
 * Online results are cached, which is not an optimisation but a requirement:
 * Discogs allows 60 requests a minute, and a detail view that called upstream
 * on every open would spend that budget on re-opens rather than on new items.
 *
 * Comics have no online source at all — see fetchOnline().
 */
class RecommendationService
{
    /**
     * Suggestion lists barely change, and a long TTL is what keeps the
     * upstream request rate proportional to distinct items viewed rather than
     * to how often they're viewed.
     */
    private const CACHE_TTL = 7 * 24 * 60 * 60;

    public function __construct(
        private readonly MediaService $mediaService,
        private readonly MediaItemMapper $mapper,
        private readonly LocalSimilarityScorer $scorer,
        private readonly DiscogsService $discogsService,
        private readonly TmdbService $tmdbService,
        private readonly OpenLibraryService $openLibraryService,
        private readonly RawgService $rawgService,
        private readonly ICacheFactory $cacheFactory,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rails for one item.
     *
     * `$include` lets a client ask for only the half it needs. The Android app
     * computes the local rail itself from its Room cache — that's what keeps
     * that rail working offline — so it asks for 'online' only, and the server
     * skips a collection-wide scan it would have thrown away.
     *
     * @param 'both'|'local'|'online' $include
     * @return array{local: list<array<string, mixed>>, online: list<array<string, mixed>>, onlineSource: string|null}
     */
    public function forItem(int $id, string $userId, int $limit = 6, string $include = 'both'): array
    {
        $item = $this->mediaService->findVisible($id, $userId);

        $wantLocal  = $include !== 'online';
        $wantOnline = $include !== 'local';

        return [
            'local'        => $wantLocal ? $this->local($item, $userId, $limit) : [],
            'online'       => $wantOnline ? $this->online($item, $userId, $limit) : [],
            'onlineSource' => $wantOnline
                && $this->onlineEnabled($userId)
                && $this->hasEnrichmentId($item)
                    ? $this->sourceName($item->getCategory())
                    : null,
        ];
    }

    /**
     * The viewer's own similar items.
     *
     * Candidates are the *viewer's* collection, never the owner's: viewing an
     * item shared with you suggests things from your own crate, and scoping it
     * this way means a share can't expose items that weren't shared.
     *
     * @return list<array<string, mixed>>
     */
    public function local(MediaItem $item, string $userId, int $limit = 6): array
    {
        $candidates = $this->mapper->findAll($userId, $item->getCategory());
        $ranked = $this->scorer->rank($item, $candidates, $limit);

        return array_map(
            static fn(MediaItem $i): array => $i->jsonSerialize(),
            $ranked,
        );
    }

    /**
     * Provider suggestions for an item, or an empty list when unavailable —
     * the setting is off, the item was never enriched, the provider needs a
     * credential the user hasn't set, or the upstream call failed. Callers
     * render nothing rather than an error: a recommendation rail is a nicety,
     * and a failed one should be invisible.
     *
     * @return list<array<string, mixed>>
     */
    public function online(MediaItem $item, string $userId, int $limit = 6): array
    {
        // sourceName() is the single source of truth for "this category has a
        // provider to ask" — a null name means no online row exists for it, so
        // there's nothing to look up or even cache.
        if (
            $this->sourceName($item->getCategory()) === null
            || !$this->onlineEnabled($userId)
            || !$this->hasEnrichmentId($item)
        ) {
            return [];
        }

        $cache = $this->cacheFactory->createDistributed('crate_recommendations');
        $key = $this->cacheKey($item);

        $cached = $cache->get($key);
        if (is_array($cached)) {
            return array_slice($cached, 0, $limit);
        }

        try {
            $results = $this->fetchOnline($item, $userId);
        } catch (DiscogsRateLimitException $e) {
            // Expected under load, and not worth surfacing: the rail just
            // doesn't appear this time. Not cached, so the next view retries.
            $this->logger->debug('Crate: Discogs rate-limited a recommendation lookup', [
                'app' => 'crate',
                'itemId' => $item->getId(),
            ]);
            return [];
        } catch (\Exception $e) {
            $this->logger->warning('Crate: recommendation lookup failed: {msg}', [
                'app' => 'crate',
                'itemId' => $item->getId(),
                'msg' => $e->getMessage(),
            ]);
            return [];
        }

        // Cached even when empty, so an item with no suggestions doesn't
        // re-ask upstream on every view.
        $cache->set($key, $results, self::CACHE_TTL);

        return array_slice($results, 0, $limit);
    }

    /**
     * Dispatch to the provider that enriched this category. Each returns rows
     * in that provider's own search-result shape, so the clients can hand a
     * recommendation straight to the existing add-from-search flow.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchOnline(MediaItem $item, string $userId): array
    {
        $enrichmentId = (string)$item->getDiscogsId();
        // Over-fetch a little so the cached list still fills the rail after
        // the caller's own slicing.
        $fetch = 8;

        return match ($item->getCategory()) {
            CrateCategories::MUSIC => $this->discogsService->similarByStyle(
                $userId,
                $item->getGenres(),
                $enrichmentId,
                $fetch,
            ),
            CrateCategories::FILM => $this->tmdbService->similar($userId, $enrichmentId, $fetch),
            CrateCategories::BOOK => $this->openLibraryService->similarBySubject(
                $item->getGenres(),
                $enrichmentId,
                $fetch,
            ),
            CrateCategories::GAME => $this->rawgService->similar(
                $userId,
                $enrichmentId,
                $item->getGenres(),
                $fetch,
            ),
            // Comics have no online row. ComicVine exposes no similarity data,
            // and the two workarounds both fail against the live API: on
            // /volumes, `filter=publisher:` is silently ignored (the same
            // 159,702 results with or without it, for any publisher id) and so
            // is `sort=`, while `character_credits` is not returned for a
            // volume even on heavily-documented series. The publisher resource
            // does list its volumes, but as id + name only — no artwork, no
            // year — so a rail would cost one call per tile and still be
            // ordered by id. Comics get the local row only.
            default => [],
        };
    }

    /** Whether the user has opted in to outbound recommendation lookups. */
    public function onlineEnabled(string $userId): bool
    {
        return $this->config->getUserValue(
            $userId,
            'crate',
            'online_recommendations',
            'no',
        ) === 'yes';
    }

    /**
     * An enrichment id is the provider's handle on this item, so without one
     * there is nothing to ask the provider about. Only enrichment sets it,
     * which is why online recommendations require an enriched item.
     */
    private function hasEnrichmentId(MediaItem $item): bool
    {
        return !empty($item->getDiscogsId());
    }

    /**
     * Cache key. Scoped by provider id and, for the categories whose query is
     * derived from stored genres rather than the id alone, by those genres too
     * — so re-enriching an item with different genres doesn't keep serving
     * suggestions built from the old ones.
     */
    private function cacheKey(MediaItem $item): string
    {
        return implode('-', [
            (string)$item->getCategory(),
            (string)$item->getDiscogsId(),
            substr(sha1((string)$item->getGenres()), 0, 12),
        ]);
    }

    /** Provider name for the rail's attribution line. */
    private function sourceName(?string $category): ?string
    {
        return match ($category) {
            CrateCategories::MUSIC => 'Discogs',
            CrateCategories::FILM  => 'TMDB',
            CrateCategories::BOOK  => 'Open Library',
            CrateCategories::GAME  => 'RAWG',
            default => null,
        };
    }
}
