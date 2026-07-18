<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CrateShare>
 */
class CrateShareMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'crate_shares', CrateShare::class);
    }

    /**
     * Shares created by $ownerUserId of the given (type, shareableId, category) tuple.
     *
     * `shareableCategory` is {@see CrateShare::CATEGORY_NONE} for album /
     * playlist / library shares — only category shares populate it with a
     * real category key.
     *
     * @return CrateShare[]
     */
    public function findByOwnerAndShareable(
        string $ownerUserId,
        string $type,
        int $shareableId,
        string $shareableCategory = CrateShare::CATEGORY_NONE,
    ): array {
        $qb = $this->db->getQueryBuilder();
        $shareableIdParam = $qb->createNamedParameter($shareableId, IQueryBuilder::PARAM_INT);
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)))
            ->andWhere($qb->expr()->eq('shareable_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('shareable_id', $shareableIdParam))
            ->andWhere($qb->expr()->eq('shareable_category', $qb->createNamedParameter($shareableCategory)));
        return $this->findEntities($qb);
    }

    /** @return CrateShare[] Shares of any type received by $userId, newest first. */
    public function findSharedWithUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /** @return CrateShare[] Every share created by $ownerUserId, newest first. */
    public function findByOwner(string $ownerUserId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    /** @throws DoesNotExistException */
    public function findByIdAndOwner(int $id, string $ownerUserId): CrateShare
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)));
        return $this->findEntity($qb);
    }

    /**
     * True if $viewerUserId has been granted a share of the given shape by anyone.
     * Used for ACL checks on specific item / playlist resources.
     */
    public function isSharedWith(
        string $viewerUserId,
        string $type,
        int $shareableId,
        string $shareableCategory = CrateShare::CATEGORY_NONE,
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $shareableIdParam = $qb->createNamedParameter($shareableId, IQueryBuilder::PARAM_INT);
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($viewerUserId)))
            ->andWhere($qb->expr()->eq('shareable_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('shareable_id', $shareableIdParam))
            ->andWhere($qb->expr()->eq('shareable_category', $qb->createNamedParameter($shareableCategory)))
            ->setMaxResults(1);
        try {
            $this->findEntity($qb);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }

    /**
     * True if $viewerUserId has a read/write share of the given shape by anyone.
     * Used to authorize writes (edit/add) by a sharee on a specific resource.
     */
    public function isWritableSharedWith(
        string $viewerUserId,
        string $type,
        int $shareableId,
        string $shareableCategory = CrateShare::CATEGORY_NONE,
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $shareableIdParam = $qb->createNamedParameter($shareableId, IQueryBuilder::PARAM_INT);
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($viewerUserId)))
            ->andWhere($qb->expr()->eq('shareable_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('shareable_id', $shareableIdParam))
            ->andWhere($qb->expr()->eq('shareable_category', $qb->createNamedParameter($shareableCategory)))
            ->andWhere($qb->expr()->eq('permission', $qb->createNamedParameter(CrateShare::PERMISSION_READWRITE)))
            ->setMaxResults(1);
        try {
            $this->findEntity($qb);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }

    /**
     * True if $viewerUserId may add a new item of $category into $ownerUserId's
     * collection — i.e. $ownerUserId granted them a read/write whole-library
     * share, or a read/write share of that specific category.
     */
    public function hasWritableCollectionShare(
        string $viewerUserId,
        string $ownerUserId,
        string $category,
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($viewerUserId)))
            ->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)))
            ->andWhere($qb->expr()->eq('permission', $qb->createNamedParameter(CrateShare::PERMISSION_READWRITE)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('shareable_type', $qb->createNamedParameter(CrateShare::TYPE_LIBRARY)),
                $qb->expr()->andX(
                    $qb->expr()->eq('shareable_type', $qb->createNamedParameter(CrateShare::TYPE_CATEGORY)),
                    $qb->expr()->eq('shareable_category', $qb->createNamedParameter($category)),
                ),
            ))
            ->setMaxResults(1);
        try {
            $this->findEntity($qb);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }

    public function alreadyShared(
        string $ownerUserId,
        string $sharedWithUserId,
        string $type,
        int $shareableId,
        string $shareableCategory = CrateShare::CATEGORY_NONE,
    ): bool {
        $qb = $this->db->getQueryBuilder();
        $shareableIdParam = $qb->createNamedParameter($shareableId, IQueryBuilder::PARAM_INT);
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)))
            ->andWhere($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId)))
            ->andWhere($qb->expr()->eq('shareable_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('shareable_id', $shareableIdParam))
            ->andWhere($qb->expr()->eq('shareable_category', $qb->createNamedParameter($shareableCategory)));
        try {
            $this->findEntity($qb);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }

    public function deleteByShareable(string $type, int $shareableId): void
    {
        $qb = $this->db->getQueryBuilder();
        $shareableIdParam = $qb->createNamedParameter($shareableId, IQueryBuilder::PARAM_INT);
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('shareable_type', $qb->createNamedParameter($type)))
            ->andWhere($qb->expr()->eq('shareable_id', $shareableIdParam));
        $qb->executeStatement();
    }

    /** Delete all shares this user has created (owner side). */
    public function deleteAllByOwner(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /** Delete all shares received by this user (recipient side). */
    public function deleteAllReceivedByUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
