<?php

declare(strict_types=1);

namespace OCA\Crate;

use OCA\Crate\Db\MediaItem;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * One of an item's image slots — its cover, or one of its two photos — and the
 * files in appdata that back it.
 *
 * An upload is written under a name of its own generation, `artwork_42_g7.jpg`,
 * and the row records the generation the slot now points at (artwork_rev,
 * photo1_rev, photo2_rev); both happen under the item's row lock. No upload
 * ever overwrites another's file, so which file is current is a fact about the
 * row rather than about the file: whoever holds the lock can remove everything
 * the row does not point at, and a replacement that committed in between —
 * at any extension, even with identical bytes — is the one the row points at.
 *
 * Uploads from before generations are at revision 0 and keep their plain names
 * (`artwork_42.jpg`) until the slot is next written.
 */
final class ItemImageSlot
{
    /** Extensions a slot's file can carry. */
    public const EXTENSIONS = ['.jpg', '.png', '.webp', '.gif'];

    private function __construct(
        /** The appdata folder the slot's files live in. */
        public readonly string $folderName,
        /** 0 for the cover; 1 or 2 for a photo slot. */
        private readonly int $photo,
    ) {
    }

    public static function artwork(): self
    {
        return new self('artwork', 0);
    }

    public static function photo(int $slot): self
    {
        return new self('photos', $slot);
    }

    /** The generation the slot is at; 0 for an upload that predates generations. */
    public function revision(MediaItem $item): int
    {
        return match ($this->photo) {
            0 => $item->getArtworkRev(),
            1 => $item->getPhoto1Rev(),
            default => $item->getPhoto2Rev(),
        };
    }

    /**
     * Move the row on to the slot's next generation, and return the name that
     * generation's file is written under.
     */
    public function advance(MediaItem $item, string $ext): string
    {
        $revision = $this->revision($item) + 1;
        match ($this->photo) {
            0 => $item->setArtworkRev($revision),
            1 => $item->setPhoto1Rev($revision),
            default => $item->setPhoto2Rev($revision),
        };
        return $this->stem($item->getId(), $revision) . $ext;
    }

    /**
     * The names the slot's current upload can have, one per extension, in the
     * order a reader should try them.
     *
     * @return list<string>
     */
    public function uploadNames(MediaItem $item): array
    {
        $stem = $this->stem($item->getId(), $this->revision($item));
        return array_map(static fn(string $ext): string => $stem . $ext, self::EXTENSIONS);
    }

    /**
     * Every file in $folder that belongs to this slot of $itemId: uploads of
     * any generation and, for the cover, cache entries of remote artwork.
     *
     * @return list<ISimpleFile>
     */
    public function files(ISimpleFolder $folder, int $itemId): array
    {
        $pattern = $this->photo === 0
            ? '/^artwork_' . $itemId . '(?:_[0-9a-f]+|_g\d+)?\.(?:jpg|png|webp|gif)$/'
            : '/^photo_' . $itemId . '_' . $this->photo . '(?:_g\d+)?\.(?:jpg|png|webp|gif)$/';

        $files = [];
        foreach ($folder->getDirectoryListing() as $file) {
            if (preg_match($pattern, $file->getName()) === 1) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Whether $item points at the file $name. The cover's upload stays
     * referenced while the enrichment snapshot holds it, since "Remove
     * enrichment data" restores the item to it; a cover cache entry is
     * referenced while the item shows that remote artwork.
     */
    public function isReferenced(MediaItem $item, string $name): bool
    {
        if ($this->photo !== 0) {
            $path = $this->photo === 1 ? $item->getPhoto1Path() : $item->getPhoto2Path();
            return $path === 'local' && in_array($name, $this->uploadNames($item), true);
        }

        $path = $item->getArtworkPath();
        if (
            ($path === 'local' || $item->getOriginalArtworkPath() === 'local')
            && in_array($name, $this->uploadNames($item), true)
        ) {
            return true;
        }
        return $path !== null && $path !== 'local'
            && str_starts_with($name, CrateArtworkFiles::cachePrefix($item->getId(), $path) . '.');
    }

    /**
     * Delete every file of the slot that $current does not point at. Call it
     * holding the item's row lock, with $current read under that lock: then no
     * change to the slot can commit between the read and the deletes.
     */
    public function removeUnreferenced(ISimpleFolder $folder, MediaItem $current): void
    {
        foreach ($this->files($folder, $current->getId()) as $file) {
            if ($this->isReferenced($current, $file->getName())) {
                continue;
            }
            try {
                $file->delete();
            } catch (NotPermittedException) {
                // Best-effort: a superseded file left behind costs disk, not
                // correctness, since readers resolve the name the row gives.
            }
        }
    }

    private function stem(int $itemId, int $revision): string
    {
        $base = $this->photo === 0 ? 'artwork_' . $itemId : 'photo_' . $itemId . '_' . $this->photo;
        return $revision > 0 ? $base . '_g' . $revision : $base;
    }
}
