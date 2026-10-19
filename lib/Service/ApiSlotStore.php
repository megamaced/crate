<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The earliest time each rate-limited credential may call its provider again,
 * held in `crate_api_slots` so that every PHP worker sees the same value.
 *
 * Taking a slot is one conditional UPDATE — "move next_at on, if it has
 * passed" — which the database applies atomically, so of any number of
 * workers trying at once exactly one succeeds. Times are integer microseconds.
 */
class ApiSlotStore
{
    private const TABLE = 'crate_api_slots';

    /** Rounds of update/read/insert before contention is reported as a wait. */
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    /**
     * Take the slot for $key at $now if it is free, and make the next one
     * free at $nextAt. Returns null when the slot was taken, otherwise the
     * time it frees up.
     */
    public function claim(string $key, int $now, int $nextAt): ?int
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('next_at', $qb->createNamedParameter($nextAt, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('slot_key', $qb->createNamedParameter($key)))
                ->andWhere($qb->expr()->lte('next_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
            if ($qb->executeStatement() > 0) {
                return null;
            }

            $current = $this->nextAt($key);
            if ($current === null) {
                if ($this->insert($key, $nextAt)) {
                    return null;
                }
                continue;
            }
            if ($current > $now) {
                return $current;
            }
            // Freed between the UPDATE and the read: try again.
        }
        // Still contended: report the slot as busy for now, so the caller
        // waits and asks again rather than calling unadmitted.
        return $nextAt;
    }

    /** Make sure the slot for $key is not free before $until. */
    public function holdUntil(string $key, int $until): void
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('next_at', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('slot_key', $qb->createNamedParameter($key)))
                ->andWhere($qb->expr()->lt('next_at', $qb->createNamedParameter($until, IQueryBuilder::PARAM_INT)));
            if ($qb->executeStatement() > 0) {
                return;
            }

            $current = $this->nextAt($key);
            if ($current === null) {
                if ($this->insert($key, $until)) {
                    return;
                }
                continue;
            }
            if ($current >= $until) {
                return;
            }
        }
    }

    private function nextAt(string $key): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('next_at')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('slot_key', $qb->createNamedParameter($key)));
        $result = $qb->executeQuery();
        try {
            $value = $result->fetchOne();
        } finally {
            $result->closeCursor();
        }
        return $value === false || $value === null ? null : (int) $value;
    }

    /** Create the row for $key; false when another worker created it first. */
    private function insert(string $key, int $nextAt): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values([
                'slot_key' => $qb->createNamedParameter($key),
                'next_at'  => $qb->createNamedParameter($nextAt, IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
            return true;
        } catch (\Exception $e) {
            if (self::isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Whether $e reports the row as already there. Nextcloud wraps database
     * errors in OCP\DB\Exception, but before 33 the query builder could let
     * Doctrine's own exception through, so both are recognised.
     */
    private static function isUniqueViolation(\Exception $e): bool
    {
        if ($e instanceof DbException) {
            return $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
        }
        return $e instanceof UniqueConstraintViolationException;
    }
}
