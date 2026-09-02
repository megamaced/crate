<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

use OCA\Crate\CrateCategories;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MediaItem>
 */
class MediaItemMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'crate_media_items', MediaItem::class);
    }

    /**
     * @return MediaItem[]
     */
    public function findAll(string $userId, ?string $category = null): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        if ($category !== null) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        }

        return $this->findEntities($qb);
    }

    /**
     * Paginated, filterable query — used by the REST API.
     *
     * @return MediaItem[]
     */
    public function findPaginated(
        string $userId,
        ?string $status = null,
        ?string $category = null,
        ?string $updatedSince = null,
        int $limit = 50,
        int $offset = 0,
        ?int $updatedSinceId = null,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($updatedSince !== null) {
            // A delta sweep is read in cursor order, which `created_at DESC`
            // is not: the client's next cursor is the last row it saw, so the
            // sort key has to be the one the cursor advances along. `id`
            // breaks ties within a second, matching the tuple predicate in
            // applyFilters().
            $qb->orderBy('updated_at', 'ASC')->addOrderBy('id', 'ASC');
        } else {
            // `created_at` is not unique — a bulk import stamps every row it
            // creates with the same second. LIMIT/OFFSET over a non-unique sort
            // key has no defined row order between pages, so the same row can
            // come back on two pages while another is never returned at all.
            // `id` breaks every tie, making the sequence total and pagination
            // lossless. Keep this in lock-step with findAll()'s ordering.
            $qb->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC');
        }

        $this->applyFilters($qb, $status, $category, $updatedSince, $updatedSinceId);

        return $this->findEntities($qb);
    }

    public function countAll(
        string $userId,
        ?string $status = null,
        ?string $category = null,
        ?string $updatedSince = null,
        ?int $updatedSinceId = null,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyFilters($qb, $status, $category, $updatedSince, $updatedSinceId);

        // Errors propagate, as they do from findPaginated(): the two run the
        // same filters over the same table, so swallowing a failure here would
        // pair a full page of rows with a total of 0.
        $result = $qb->executeQuery();
        $val = $result->fetchOne();
        $result->closeCursor();
        return (int) ($val ?? 0);
    }

    /**
     * Apply the optional status / category / updatedSince filters used by
     * findPaginated() and countAll() to a query builder. Extracted to keep
     * the two methods in lock-step — any new filter only needs to be added
     * here, to the method signatures, and to the MediaService.
     */
    private function applyFilters(
        IQueryBuilder $qb,
        ?string $status,
        ?string $category,
        ?string $updatedSince,
        ?int $updatedSinceId = null,
    ): void {
        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }
        if ($category !== null) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        }
        if ($updatedSince === null) {
            return;
        }

        // `updated_at` is a DATETIME — whole seconds on every supported
        // engine — so a timestamp alone cannot separate two rows edited in the
        // same second. A strict `>` against the highest timestamp a sweep
        // returned therefore drops any row stamped that same second which the
        // sweep had not yet reached, and nothing later re-reports it: an edit
        // changes no row count, so a client's count-drift check never
        // escalates to a full sweep. The row simply stays stale.
        //
        // `(updated_at, id)` is the cursor that does separate them. A client
        // that sends back the last row's id gets an exact resume point.
        $ts = $qb->createNamedParameter($updatedSince);
        if ($updatedSinceId === null) {
            // No id: the boundary second has to be re-sent in full, since
            // there is no way to tell which of its rows the client already
            // has. Costs a repeat of the rows sharing that one second and
            // loses none of them. Clients should send `updatedSinceId`.
            $qb->andWhere($qb->expr()->gte('updated_at', $ts));
            return;
        }
        $qb->andWhere($qb->expr()->orX(
            $qb->expr()->gt('updated_at', $ts),
            $qb->expr()->andX(
                $qb->expr()->eq('updated_at', $ts),
                $qb->expr()->gt('id', $qb->createNamedParameter($updatedSinceId, IQueryBuilder::PARAM_INT)),
            ),
        ));
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByUser(int $id, string $userId): MediaItem
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    /**
     * Find a media item that $viewerUserId can read — they own it, or there is
     * a share row that grants them access (per-album, whole-library from the
     * item's owner, or a matching per-category share from the item's owner).
     *
     * Read-only callers (artwork GET, photo GET, item detail GET) should use
     * this. Write callers (upload, delete, enrich, fetchMarketValue) must
     * continue to use findByUser() — sharees are read-only in this release.
     *
     * @throws DoesNotExistException
     */
    public function findVisibleForUser(int $id, string $viewerUserId): MediaItem
    {
        // Cheap owner-case fast path — the common case is the user reading
        // their own items, and findByUser is a single indexed lookup.
        try {
            return $this->findByUser($id, $viewerUserId);
        } catch (DoesNotExistException) {
            // Fall through to share resolution.
        }

        // Sharee case. Single LEFT JOIN against crate_shares constrained to
        // shares of this exact item (album-level), or the item's owner+category
        // (library / category-level). DISTINCT defends against multiple
        // overlapping shares — e.g. owner shared both the whole library AND
        // this specific album — returning the same item twice.
        $qb = $this->db->getQueryBuilder();
        $idParam     = $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT);
        $viewerParam = $qb->createNamedParameter($viewerUserId);

        $qb->select('mi.*')
            ->from($this->getTableName(), 'mi')
            ->leftJoin(
                'mi',
                'crate_shares',
                'cs',
                $qb->expr()->andX(
                    $qb->expr()->eq('cs.shared_with_user_id', $viewerParam),
                    $qb->expr()->orX(
                        $qb->expr()->andX(
                            $qb->expr()->eq('cs.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_ALBUM)),
                            $qb->expr()->eq('cs.shareable_id', $idParam),
                            // crate_shares has no FK to crate_media_items, so an
                            // orphaned share row over a recycled autoincrement id
                            // would otherwise grant access to an unrelated item.
                            $qb->expr()->eq('cs.owner_user_id', 'mi.user_id'),
                        ),
                        $qb->expr()->andX(
                            $qb->expr()->eq('cs.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_LIBRARY)),
                            $qb->expr()->eq('cs.owner_user_id', 'mi.user_id'),
                        ),
                        $qb->expr()->andX(
                            $qb->expr()->eq('cs.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_CATEGORY)),
                            $qb->expr()->eq('cs.owner_user_id', 'mi.user_id'),
                            $qb->expr()->eq('cs.shareable_category', 'mi.category'),
                        ),
                    ),
                ),
            )
            // Playlist-share case: the item is a track in a playlist that has
            // been shared with the viewer. Join playlist membership to the item,
            // then a matching playlist share row. Kept as a separate join since
            // it targets crate_playlist_items rather than the item directly.
            ->leftJoin(
                'mi',
                'crate_playlist_items',
                'pi',
                $qb->expr()->eq('pi.media_item_id', 'mi.id'),
            )
            ->leftJoin(
                'pi',
                'crate_shares',
                'csp',
                $qb->expr()->andX(
                    $qb->expr()->eq('csp.shared_with_user_id', $viewerParam),
                    $qb->expr()->eq('csp.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_PLAYLIST)),
                    $qb->expr()->eq('csp.shareable_id', 'pi.playlist_id'),
                    // A playlist share exposes the sharer's own tracks, plus
                    // whatever the viewer contributed themselves. Only the
                    // playlist's owner can create a share of it, so the share's
                    // owner_user_id is the owner this item has to belong to —
                    // otherwise a sharee could relay a third user's item onward
                    // by adding it to a playlist and sharing that.
                    $qb->expr()->orX(
                        $qb->expr()->eq('csp.owner_user_id', 'mi.user_id'),
                        $qb->expr()->eq('mi.user_id', $viewerParam),
                    ),
                ),
            )
            ->where($qb->expr()->eq('mi.id', $idParam))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNotNull('cs.id'),
                $qb->expr()->isNotNull('csp.id'),
            ))
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * Find a media item that $viewerUserId may WRITE (edit/enrich/artwork/etc) —
     * they own it, or there is a read/write share granting them access to it:
     * a per-album RW share, or a whole-library / per-category RW share from the
     * item's owner.
     *
     * Deliberately narrower than findVisibleForUser: playlist shares are NOT
     * included, because read/write on a playlist governs its track list, not
     * the right to modify the underlying media items (which may belong to other
     * users). Deletes must still use findByUser() — sharees cannot delete.
     *
     * @throws DoesNotExistException
     */
    public function findWritableForUser(int $id, string $viewerUserId): MediaItem
    {
        // Owner fast path.
        try {
            return $this->findByUser($id, $viewerUserId);
        } catch (DoesNotExistException) {
            // Fall through to read/write share resolution.
        }

        $qb = $this->db->getQueryBuilder();
        $idParam     = $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT);
        $viewerParam = $qb->createNamedParameter($viewerUserId);
        $rwParam     = $qb->createNamedParameter(CrateShare::PERMISSION_READWRITE);

        $qb->select('mi.*')
            ->from($this->getTableName(), 'mi')
            ->leftJoin(
                'mi',
                'crate_shares',
                'cs',
                $qb->expr()->andX(
                    $qb->expr()->eq('cs.shared_with_user_id', $viewerParam),
                    $qb->expr()->eq('cs.permission', $rwParam),
                    $qb->expr()->orX(
                        $qb->expr()->andX(
                            $qb->expr()->eq('cs.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_ALBUM)),
                            $qb->expr()->eq('cs.shareable_id', $idParam),
                            // As in findVisibleForUser: the share only counts
                            // while its owner still owns the item.
                            $qb->expr()->eq('cs.owner_user_id', 'mi.user_id'),
                        ),
                        $qb->expr()->andX(
                            $qb->expr()->eq('cs.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_LIBRARY)),
                            $qb->expr()->eq('cs.owner_user_id', 'mi.user_id'),
                        ),
                        $qb->expr()->andX(
                            $qb->expr()->eq('cs.shareable_type', $qb->createNamedParameter(CrateShare::TYPE_CATEGORY)),
                            $qb->expr()->eq('cs.owner_user_id', 'mi.user_id'),
                            $qb->expr()->eq('cs.shareable_category', 'mi.category'),
                        ),
                    ),
                ),
            )
            ->where($qb->expr()->eq('mi.id', $idParam))
            ->andWhere($qb->expr()->isNotNull('cs.id'))
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    public function deleteAllByUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    public function deleteAllByUserAndCategory(string $userId, string $category): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        $qb->executeStatement();
    }

    /** Find by id without user ownership check — used for shared-item access. */
    public function findById(int $id): MediaItem
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    /**
     * Bulk lookup by id, no user ownership check. Missing IDs are silently
     * dropped. Used by share listings to avoid N+1 per-share queries.
     *
     * @param int[] $ids
     * @return MediaItem[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $this->findEntities($qb);
    }

    /**
     * Return ids of items that have a non-empty discogs_id for the user,
     * restricted to categories with a market-value source. Film/book rows
     * are excluded so their enrichment ids (TMDB / Open Library) are not
     * misread as Discogs release ids by the market-value refresh flow.
     * Used by the refresh-all market-value flow so we don't pull entire
     * collections into PHP just to filter.
     *
     * @return int[]
     */
    public function findIdsWithEnrichmentForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNotNull('discogs_id'))
            ->andWhere($qb->expr()->neq('discogs_id', $qb->createNamedParameter('')))
            ->andWhere($qb->expr()->in(
                'category',
                $qb->createNamedParameter(CrateCategories::MARKET_CATEGORIES, IQueryBuilder::PARAM_STR_ARRAY),
            ));
        $cursor = $qb->executeQuery();
        $ids = [];
        while ($row = $cursor->fetch()) {
            $ids[] = (int) $row['id'];
        }
        $cursor->closeCursor();
        return $ids;
    }

    /**
     * Full-text search over title and artist for a user (case-insensitive).
     *
     * `$excludeCategories` is applied in SQL rather than to the returned rows,
     * so hiding a category shrinks the candidate set instead of eating into
     * the result limit.
     *
     * @param list<string> $excludeCategories
     * @return MediaItem[]
     */
    public function search(string $userId, string $term, array $excludeCategories = []): array
    {
        $like = '%' . $this->db->escapeLikeParameter(strtolower($term)) . '%';
        $qb   = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like($qb->func()->lower('title'), $qb->createNamedParameter($like)),
                    $qb->expr()->like($qb->func()->lower('artist'), $qb->createNamedParameter($like)),
                )
            );

        if (!empty($excludeCategories)) {
            $qb->andWhere($qb->expr()->notIn(
                'category',
                $qb->createNamedParameter($excludeCategories, IQueryBuilder::PARAM_STR_ARRAY),
            ));
        }

        $qb->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(10);

        return $this->findEntities($qb);
    }
}
