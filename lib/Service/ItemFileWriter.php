<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\ItemImageSlot;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Replaces or removes the file behind one of an item's image slots — its
 * cover, or a photo — so that concurrent changes to the same slot settle on
 * the last one to commit, with that one's file in place.
 *
 * A change runs in two steps, each holding the item row's lock:
 *
 *  1. Record the change on the row, move the slot to a new generation, write
 *     the new file under that generation's name, and commit. Nothing is
 *     deleted yet, so a failed write rolls back with the previous file intact.
 *  2. Re-read the row and delete every file of the slot it does not point at.
 *
 * Because each generation has a name of its own, step 2 never has to tell a
 * superseded file from its replacement by its content or metadata: the row
 * names the file to keep (see ItemImageSlot). A change that committed after
 * this one is what the row points at by then, and survives this cleanup.
 */
class ItemFileWriter
{
    public function __construct(
        private readonly IDBConnection $db,
        private readonly MediaItemMapper $mapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Point the slot at new bytes.
     *
     * @param \Closure(MediaItem): void $mark the rest of the row change, e.g. setArtworkPath('local')
     * @throws DoesNotExistException when the caller cannot write the item
     */
    public function write(
        int $itemId,
        string $userId,
        ISimpleFolder $folder,
        ItemImageSlot $slot,
        \Closure $mark,
        string $ext,
        string $bytes,
    ): void {
        $this->db->beginTransaction();
        try {
            $item = $this->lockedForWrite($itemId, $userId);
            $mark($item);
            $name = $slot->advance($item, $ext);
            $this->mapper->update($item);
            // A fresh generation's name, unless an earlier attempt at the same
            // generation rolled back after writing it; that file is unused.
            try {
                $folder->getFile($name)->putContent($bytes);
            } catch (NotFoundException) {
                $folder->newFile($name, $bytes);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->tidy($itemId, $folder, $slot);
    }

    /**
     * Clear the slot.
     *
     * @param \Closure(MediaItem): void $mark the row change, e.g. setArtworkPath(null)
     * @throws DoesNotExistException when the caller cannot write the item
     */
    public function remove(
        int $itemId,
        string $userId,
        ?ISimpleFolder $folder,
        ItemImageSlot $slot,
        \Closure $mark,
    ): void {
        $this->db->beginTransaction();
        try {
            $item = $this->lockedForWrite($itemId, $userId);
            $mark($item);
            $this->mapper->update($item);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if ($folder !== null) {
            $this->tidy($itemId, $folder, $slot);
        }
    }

    /**
     * Step 2: under the row lock, delete every file of the slot the row does
     * not point at. Best-effort: a superseded file left behind costs disk, not
     * correctness, because readers resolve the name the row gives.
     */
    public function tidy(int $itemId, ISimpleFolder $folder, ItemImageSlot $slot): void
    {
        $this->db->beginTransaction();
        try {
            $this->mapper->lockRow($itemId);
            $slot->removeUnreferenced($folder, $this->mapper->findById($itemId));
            $this->db->commit();
        } catch (DoesNotExistException) {
            // Deleted meanwhile; deleting the item removes its files.
            $this->db->rollBack();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->warning('Could not remove superseded files for item {id}: {msg}', [
                'id'  => $itemId,
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
        }
    }

    /**
     * Lock the row first: the entity update writes nothing when no field
     * changes, and a row that is not written is not locked.
     */
    private function lockedForWrite(int $itemId, string $userId): MediaItem
    {
        $this->mapper->lockRow($itemId);
        return $this->mapper->findWritableForUser($itemId, $userId);
    }
}
