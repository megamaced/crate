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
    public function findAll(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
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
