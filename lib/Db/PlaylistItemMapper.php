<?php

declare(strict_types=1);

namespace OCA\Crate\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<PlaylistItem>
 */
class PlaylistItemMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'crate_playlist_items', PlaylistItem::class);
    }

    /** @return PlaylistItem[] ordered by position */
    public function findByPlaylist(int $playlistId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('playlist_id', $qb->createNamedParameter($playlistId, IQueryBuilder::PARAM_INT)))
            ->orderBy('position', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Single-query variant of findByPlaylist for many playlists at once.
     * Returns a map keyed by playlist_id; each value is items ordered by position.
     *
     * @param int[] $playlistIds
     * @return array<int, PlaylistItem[]>
     */
    public function findByPlaylistIds(array $playlistIds): array
    {
        $grouped = [];
        foreach ($playlistIds as $id) {
            $grouped[$id] = [];
        }
        if (empty($playlistIds)) {
            return $grouped;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'playlist_id',
                $qb->createNamedParameter($playlistIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->orderBy('playlist_id', 'ASC')
            ->addOrderBy('position', 'ASC');
        foreach ($this->findEntities($qb) as $row) {
            $grouped[$row->getPlaylistId()][] = $row;
        }
        return $grouped;
    }

    public function existsInPlaylist(int $playlistId, int $mediaItemId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $playlistIdParam = $qb->createNamedParameter($playlistId, IQueryBuilder::PARAM_INT);
        $mediaItemIdParam = $qb->createNamedParameter($mediaItemId, IQueryBuilder::PARAM_INT);
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('playlist_id', $playlistIdParam))
            ->andWhere($qb->expr()->eq('media_item_id', $mediaItemIdParam))
            // findEntity throws MultipleObjectsReturnedException on more than
            // one match, which this only ever needs to know the existence of.
            ->setMaxResults(1);
        try {
            $this->findEntity($qb);
            return true;
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return false;
        }
    }

    public function maxPosition(int $playlistId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('position'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('playlist_id', $qb->createNamedParameter($playlistId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $val = $result->fetchOne();
        $result->closeCursor();
        return $val !== false ? (int) $val : -1;
    }

    public function deleteByPlaylistAndItem(int $playlistId, int $mediaItemId): void
    {
        $qb = $this->db->getQueryBuilder();
        $playlistIdParam = $qb->createNamedParameter($playlistId, IQueryBuilder::PARAM_INT);
        $mediaItemIdParam = $qb->createNamedParameter($mediaItemId, IQueryBuilder::PARAM_INT);
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('playlist_id', $playlistIdParam))
            ->andWhere($qb->expr()->eq('media_item_id', $mediaItemIdParam));
        $qb->executeStatement();
    }

    public function deleteByPlaylist(int $playlistId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('playlist_id', $qb->createNamedParameter($playlistId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function deleteByMediaItem(int $mediaItemId): void
    {
        $qb = $this->db->getQueryBuilder();
        $mediaItemIdParam = $qb->createNamedParameter($mediaItemId, IQueryBuilder::PARAM_INT);
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('media_item_id', $mediaItemIdParam));
        $qb->executeStatement();
    }
}
