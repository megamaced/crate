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

    public function existsInPlaylist(int $playlistId, int $mediaItemId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $playlistIdParam = $qb->createNamedParameter($playlistId, IQueryBuilder::PARAM_INT);
        $mediaItemIdParam = $qb->createNamedParameter($mediaItemId, IQueryBuilder::PARAM_INT);
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('playlist_id', $playlistIdParam))
            ->andWhere($qb->expr()->eq('media_item_id', $mediaItemIdParam));
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

    /** Delete all playlist items belonging to any playlist owned by $userId. */
    public function deleteByUserPlaylists(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $sub = $this->db->getQueryBuilder();
        $sub->select('id')
            ->from('crate_playlists')
            ->where($sub->expr()->eq('user_id', $sub->createNamedParameter($userId)));
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('playlist_id', $qb->createFunction('(' . $sub->getSQL() . ')')));
        $qb->executeStatement();
    }
}
