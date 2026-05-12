<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

use OCA\Crate\CrateCategories;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * @extends QBMapper<MediaItem>
 */
class MediaItemMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
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
            ->orderBy('created_at', 'DESC');

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
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($qb, $status, $category, $updatedSince);

        return $this->findEntities($qb);
    }

    public function countAll(
        string $userId,
        ?string $status = null,
        ?string $category = null,
        ?string $updatedSince = null,
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $this->applyFilters($qb, $status, $category, $updatedSince);

        try {
            $result = $qb->executeQuery();
            $val = $result->fetchOne();
            $result->closeCursor();
            return (int) ($val ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('MediaItemMapper::countAll failed: {msg}', [
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
            return 0;
        }
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
    ): void {
        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }
        if ($category !== null) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        }
        if ($updatedSince !== null) {
            $qb->andWhere($qb->expr()->gt('updated_at', $qb->createNamedParameter($updatedSince)));
        }
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
     * @return MediaItem[]
     */
    public function search(string $userId, string $term): array
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
            )
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(10);

        return $this->findEntities($qb);
    }
}
