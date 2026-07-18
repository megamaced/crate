<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Exception\DiscogsRateLimitException;
use OCP\AppFramework\Http;

/**
 * Dispatch enrichment of a MediaItem to the category-appropriate upstream
 * service (Discogs / TMDB / Open Library / RAWG / ComicVine) and apply the
 * result via MediaService::apply*Data().
 *
 * Extracted from MediaController so the controller stays in the HTTP layer
 * and the per-category logic lives together. Each public `enrich*()` method
 * returns an EnrichmentResult describing the outcome; the controller maps
 * that to a DataResponse.
 */
class EnrichmentService
{
    public function __construct(
        private readonly MediaService $mediaService,
        private readonly DiscogsService $discogsService,
        private readonly TmdbService $tmdbService,
        private readonly OpenLibraryService $openLibraryService,
        private readonly RawgService $rawgService,
        private readonly ComicVineService $comicVineService,
    ) {
    }

    /**
     * Dispatch by item category. Returns an EnrichmentResult that the
     * controller can translate into a DataResponse.
     */
    public function enrich(int $id, string $userId): EnrichmentResult
    {
        // Owner or read/write sharee — enrich mutates the item.
        $item     = $this->mediaService->findWritable($id, $userId);
        $category = $item->getCategory();

        return match ($category) {
            CrateCategories::MUSIC => $this->enrichMusic($id, $userId, $item),
            CrateCategories::FILM  => $this->enrichFilm($id, $userId, $item),
            CrateCategories::BOOK  => $this->enrichBook($id, $userId, $item),
            CrateCategories::GAME  => $this->enrichGame($id, $userId, $item),
            CrateCategories::COMIC => $this->enrichComic($id, $userId, $item),
            default => EnrichmentResult::error(
                'Unknown category: ' . (string) $category,
                Http::STATUS_BAD_REQUEST,
            ),
        };
    }

    private function enrichMusic(int $id, string $userId, MediaItem $item): EnrichmentResult
    {
        try {
            if (empty($item->getDiscogsId())) {
                $query   = trim($item->getArtist() . ' ' . $item->getTitle());
                $results = $this->discogsService->search($userId, $query);
                if (empty($results)) {
                    return EnrichmentResult::error(
                        'No Discogs match found for this item.',
                        Http::STATUS_NOT_FOUND,
                    );
                }

                $itemFormat = $item->getFormat();
                $matching   = array_values(array_filter(
                    $results,
                    fn(array $r) => ($r['format'] ?? '') === $itemFormat,
                ));
                $best       = !empty($matching) ? $matching[0] : $results[0];
                $discogsId  = $best['discogsId'] ?? '';
                if ($discogsId === '') {
                    return EnrichmentResult::error(
                        'No Discogs match found for this item.',
                        Http::STATUS_NOT_FOUND,
                    );
                }
                $item = $this->mediaService->patchDiscogsId($id, $userId, $discogsId);
            }

            $release = $this->discogsService->getRelease($userId, $item->getDiscogsId());
            if (empty($release)) {
                return EnrichmentResult::error(
                    'Could not fetch release from Discogs. Check your token.',
                    Http::STATUS_BAD_GATEWAY,
                );
            }

            $artist   = [];
            $artistId = $release['discogsArtistId'] ?? $item->getDiscogsArtistId();
            if (!empty($artistId)) {
                $artist = $this->discogsService->getArtist($userId, $artistId);
            }

            return EnrichmentResult::ok(
                $this->mediaService->applyReleaseData($id, $userId, $release, $artist)
            );
        } catch (DiscogsRateLimitException) {
            return EnrichmentResult::error(
                'Discogs rate limit exceeded. The queue will retry automatically.',
                Http::STATUS_TOO_MANY_REQUESTS,
            );
        }
    }

    private function enrichFilm(int $id, string $userId, MediaItem $item): EnrichmentResult
    {
        $tmdbId = $item->getDiscogsId();
        if (empty($tmdbId)) {
            $results = $this->tmdbService->search($userId, trim($item->getTitle()));
            if (empty($results)) {
                return EnrichmentResult::error(
                    'No TMDB match found. Add a TMDB token in Settings.',
                    Http::STATUS_NOT_FOUND,
                );
            }
            $tmdbId = (string)($results[0]['tmdbId'] ?? '');
        }
        if ($tmdbId === '') {
            return EnrichmentResult::error('No TMDB ID available.', Http::STATUS_NOT_FOUND);
        }
        $movie = $this->tmdbService->getMovie($userId, $tmdbId);
        if (empty($movie)) {
            return EnrichmentResult::error(
                'Could not fetch film from TMDB. Check your token.',
                Http::STATUS_BAD_GATEWAY,
            );
        }
        return EnrichmentResult::ok($this->mediaService->applyTmdbData($id, $userId, $movie));
    }

    private function enrichBook(int $id, string $userId, MediaItem $item): EnrichmentResult
    {
        $workKey = $item->getDiscogsId();
        if (empty($workKey)) {
            $results = $this->openLibraryService->search(trim($item->getArtist() . ' ' . $item->getTitle()));
            if (empty($results)) {
                return EnrichmentResult::error(
                    'No Open Library match found.',
                    Http::STATUS_NOT_FOUND,
                );
            }
            $workKey = (string)($results[0]['workKey'] ?? '');
            $doc     = $results[0];
        } else {
            $doc = ['workKey' => $workKey];
        }
        if ($workKey === '') {
            return EnrichmentResult::error(
                'No Open Library work key available.',
                Http::STATUS_NOT_FOUND,
            );
        }
        $work = $this->openLibraryService->getWork($workKey);
        return EnrichmentResult::ok($this->mediaService->applyOpenLibraryData($id, $userId, $doc, $work));
    }

    private function enrichGame(int $id, string $userId, MediaItem $item): EnrichmentResult
    {
        $rawgId = $item->getDiscogsId();
        if (empty($rawgId)) {
            $results = $this->rawgService->search($userId, trim($item->getTitle()));
            if (empty($results)) {
                return EnrichmentResult::error(
                    'No RAWG match found. Add a RAWG API key in Settings.',
                    Http::STATUS_NOT_FOUND,
                );
            }
            $rawgId = (string)($results[0]['rawgId'] ?? '');
        }
        if ($rawgId === '') {
            return EnrichmentResult::error('No RAWG ID available.', Http::STATUS_NOT_FOUND);
        }
        $game = $this->rawgService->getGame($userId, $rawgId);
        if (empty($game)) {
            return EnrichmentResult::error(
                'Could not fetch game from RAWG. Check your API key.',
                Http::STATUS_BAD_GATEWAY,
            );
        }
        return EnrichmentResult::ok($this->mediaService->applyRawgData($id, $userId, $game));
    }

    private function enrichComic(int $id, string $userId, MediaItem $item): EnrichmentResult
    {
        $volumeId = $item->getDiscogsId();
        if (empty($volumeId)) {
            $results = $this->comicVineService->search($userId, trim($item->getTitle()));
            if (empty($results)) {
                return EnrichmentResult::error(
                    'No ComicVine match found. Add a ComicVine API key in Settings.',
                    Http::STATUS_NOT_FOUND,
                );
            }
            $volumeId = (string)($results[0]['comicVineId'] ?? '');
        }
        if ($volumeId === '') {
            return EnrichmentResult::error(
                'No ComicVine volume ID available.',
                Http::STATUS_NOT_FOUND,
            );
        }
        $volume = $this->comicVineService->getVolume($userId, $volumeId);
        if (empty($volume)) {
            return EnrichmentResult::error(
                'Could not fetch volume from ComicVine. Check your API key.',
                Http::STATUS_BAD_GATEWAY,
            );
        }
        return EnrichmentResult::ok($this->mediaService->applyComicVineData($id, $userId, $volume));
    }
}
