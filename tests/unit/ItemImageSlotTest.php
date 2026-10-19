<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\ItemImageSlot;
use PHPUnit\Framework\TestCase;

/**
 * Which file a slot is using is a fact about the row: its generation names the
 * file. An upload from before generations is at revision 0 and keeps its plain
 * name, so existing covers and photos are still found after the upgrade.
 */
class ItemImageSlotTest extends TestCase
{
    public function testAnUploadFromBeforeGenerationsKeepsItsPlainName(): void
    {
        $item = $this->item();

        self::assertSame(
            ['artwork_5.jpg', 'artwork_5.png', 'artwork_5.webp', 'artwork_5.gif'],
            ItemImageSlot::artwork()->uploadNames($item),
        );
        self::assertSame('photo_5_2.jpg', ItemImageSlot::photo(2)->uploadNames($item)[0]);
    }

    public function testEachUploadIsANewGenerationWithANameOfItsOwn(): void
    {
        $item = $this->item();

        self::assertSame('artwork_5_g1.png', ItemImageSlot::artwork()->advance($item, '.png'));
        self::assertSame('artwork_5_g2.png', ItemImageSlot::artwork()->advance($item, '.png'));
        self::assertSame('artwork_5_g2.png', ItemImageSlot::artwork()->uploadNames($item)[1]);
        self::assertSame('photo_5_1_g1.jpg', ItemImageSlot::photo(1)->advance($item, '.jpg'));
        self::assertSame(0, $item->getPhoto2Rev());
    }

    public function testASlotListsOnlyItsOwnFiles(): void
    {
        $folder = new FakeSimpleFolder();
        foreach (
            [
                'artwork_5.jpg', 'artwork_5_g2.png', 'artwork_5_00ff00ff00ff00ff.jpg',
                'artwork_50.jpg', 'artwork_5_g2.png.part', 'photo_5_1_g1.jpg', 'photo_5_2.jpg', 'photo_51_1.jpg',
            ] as $name
        ) {
            $folder->write($name, 'x');
        }
        $names = static fn(array $files): array => array_map(static fn($f) => $f->getName(), $files);

        self::assertSame(
            ['artwork_5.jpg', 'artwork_5_g2.png', 'artwork_5_00ff00ff00ff00ff.jpg'],
            $names(ItemImageSlot::artwork()->files($folder, 5)),
        );
        self::assertSame(['photo_5_1_g1.jpg'], $names(ItemImageSlot::photo(1)->files($folder, 5)));
        self::assertSame(['photo_5_2.jpg'], $names(ItemImageSlot::photo(2)->files($folder, 5)));
    }

    public function testOnlyWhatTheRowPointsAtIsReferenced(): void
    {
        $item = $this->item();
        $item->setArtworkPath('local');
        $item->setArtworkRev(2);
        $slot = ItemImageSlot::artwork();

        self::assertTrue($slot->isReferenced($item, 'artwork_5_g2.png'));
        self::assertFalse($slot->isReferenced($item, 'artwork_5_g1.png'));
        self::assertFalse($slot->isReferenced($item, 'artwork_5.png'));

        // A remote cover references its own cache entry, and no upload.
        $item->setArtworkPath('https://i.discogs.com/a.jpg');
        self::assertFalse($slot->isReferenced($item, 'artwork_5_g2.png'));

        // Unless the enrichment snapshot holds the upload for restoring.
        $item->setOriginalArtworkPath('local');
        self::assertTrue($slot->isReferenced($item, 'artwork_5_g2.png'));

        $photo = $this->item();
        $photo->setPhoto1Rev(1);
        self::assertFalse(ItemImageSlot::photo(1)->isReferenced($photo, 'photo_5_1_g1.jpg'));
        $photo->setPhoto1Path('local');
        self::assertTrue(ItemImageSlot::photo(1)->isReferenced($photo, 'photo_5_1_g1.jpg'));
    }

    private function item(): MediaItem
    {
        $item = new MediaItem();
        $item->setId(5);
        return $item;
    }
}
