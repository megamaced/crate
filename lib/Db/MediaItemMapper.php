<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

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

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }
        if ($category !== null) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        }
        if ($updatedSince !== null) {
            $qb->andWhere($qb->expr()->gt('updated_at', $qb->createNamedParameter($updatedSince)));
        }

        return $this->findEntities($qb);
    }

    public function countAll(string $userId, ?string $status = null, ?string $category = null, ?string $updatedSince = null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }
        if ($category !== null) {
            $qb->andWhere($qb->expr()->eq('category', $qb->createNamedParameter($category)));
        }
        if ($updatedSince !== null) {
            $qb->andWhere($qb->expr()->gt('updated_at', $qb->createNamedParameter($updatedSince)));
        }

        $result = $qb->executeQuery();
        $val = $result->fetchOne();
        $result->closeCursor();
        return (int) $val;
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
