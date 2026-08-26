<?php

declare(strict_types=1);

namespace OCA\Crate;

use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Naming scheme for the artwork files Crate keeps in appdata.
 *
 * Two kinds of file live in the `artwork` folder, and the difference matters:
 *
 *  - an upload, `artwork_<itemId>.<ext>`, which *is* the artwork. The item's
 *    `artwork_path` is the literal 'local', so the item id alone identifies it.
 *  - a proxy cache entry, `artwork_<itemId>_<digest>.<ext>`, which is a copy of
 *    the remote cover at `artwork_path`. The digest is of that URL, so the name
 *    identifies the image and not merely the item that asked for it.
 *
 * The digest is what keeps a cache entry honest. Named by item id alone, an
 * entry is served for whatever `artwork_path` the row happens to hold now —
 * a cover replaced by a different one at the same extension, artwork that
 * outlived its item on a database whose autoincrement later reissued the id, or
 * a file any of the best-effort purge paths (all of which run after the commit
 * and swallow their own failures) did not manage to remove. Every one of those
 * shows one item's picture on another. With the source URL in the name a stale
 * file simply never matches, and the purge below is a tidy-up rather than the
 * only thing standing between two items' covers.
 */
final class CrateArtworkFiles
{
    /** Extensions an artwork file can carry. */
    public const EXTENSIONS = ['.jpg', '.png', '.webp', '.gif'];

    /** Every file in the folder that belongs to a media item's artwork. */
    private const NAME_PATTERN = '/^artwork_(\d+)(?:_[0-9a-f]+)?\.(?:jpg|png|webp|gif)$/';

    /** Name a user-uploaded cover for $itemId is stored under. */
    public static function uploadName(int $itemId, string $ext): string
    {
        return 'artwork_' . $itemId . $ext;
    }

    /** Name the artwork proxy caches $sourceUrl under for $itemId. */
    public static function cacheName(int $itemId, string $sourceUrl, string $ext): string
    {
        return 'artwork_' . $itemId . '_' . substr(sha1($sourceUrl), 0, 16) . $ext;
    }

    /**
     * Delete every artwork file belonging to $itemIds — uploads and cache
     * entries alike, at any extension and for any cover the items ever had, so
     * nothing survives to be picked up later.
     *
     * The folder is listed once for the whole batch: a wipe deletes thousands
     * of items, and a listing per item would make it quadratic.
     */
    public static function deleteAll(ISimpleFolder $folder, int ...$itemIds): void
    {
        if (empty($itemIds)) {
            return;
        }
        $wanted = array_flip(array_map(static fn(int $id): string => (string)$id, $itemIds));

        foreach ($folder->getDirectoryListing() as $file) {
            if (!preg_match(self::NAME_PATTERN, $file->getName(), $m)) {
                continue;
            }
            if (!isset($wanted[$m[1]])) {
                continue;
            }
            try {
                $file->delete();
            } catch (NotPermittedException) {
                // Best-effort: a file we cannot unlink is not a reason to fail
                // the delete or the enrichment that triggered the sweep.
            }
        }
    }

    private function __construct()
    {
    }
}
