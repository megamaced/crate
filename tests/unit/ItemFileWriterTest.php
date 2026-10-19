<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\ItemImageSlot;
use OCA\Crate\Service\ItemFileWriter;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Two changes to one image slot used to each clean up after committing by
 * keeping only their own file name — so an upload of a JPEG and one of a PNG
 * could each delete the other's file and leave the item with neither, and a
 * removal's cleanup could delete an upload that committed in between.
 *
 * Each upload now writes a generation of its own, and cleanup keeps what the
 * row points at. The interleavings are driven from the connection: a change's
 * first commit runs whatever the test scheduled to happen before its cleanup.
 * Nothing here reads file metadata: on Nextcloud's local storage an overwrite
 * in the same second at the same size keeps its etag, mtime and size.
 */
#[AllowMockObjectsWithoutExpectations]
class ItemFileWriterTest extends TestCase
{
    private FakeSimpleFolder $folder;
    private MediaItem $row;

    /** @var list<\Closure(): void> run, in order, at the next commits */
    private array $atCommit = [];

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->folder = new FakeSimpleFolder();
        $this->row = new MediaItem();
        $this->row->setId(42);
        $this->row->setUserId('alice');
    }

    public function testTwoUploadsAtDifferentExtensionsSettleOnTheLast(): void
    {
        // A commits its JPEG; before A's cleanup, B uploads a PNG and cleans up.
        $this->atCommit = [fn() => $this->upload('.png', 'B')];

        $this->upload('.jpg', 'A');

        self::assertSame(['artwork_42_g2.png' => 'B'], $this->folder->files);
        self::assertSame('local', $this->row->getArtworkPath());
        self::assertSame(2, $this->row->getArtworkRev());
    }

    public function testAnIdenticalReplacementAfterARemovalSurvivesTheRemovalsCleanup(): void
    {
        // The same bytes at the same extension within the same second: nothing
        // about the file tells the replacement from what it replaced.
        $this->upload('.jpg', 'cover');
        $this->atCommit = [fn() => $this->upload('.jpg', 'cover')];

        $this->remove();

        self::assertSame(['artwork_42_g2.jpg' => 'cover'], $this->folder->files);
        self::assertSame('local', $this->row->getArtworkPath());
    }

    public function testARemovalSupersedesEveryGenerationAndTheCache(): void
    {
        $this->folder->write('artwork_42.png', 'from before generations');
        $this->folder->write('artwork_42_g3.jpg', 'orphan of a rolled-back write');
        $this->folder->write('artwork_42_00ff00ff00ff00ff.jpg', 'cached cover');

        $this->remove();

        self::assertSame([], $this->folder->files);
        self::assertNull($this->row->getArtworkPath());
    }

    public function testAnUploadReplacesWhatTheSlotHeldButNotOtherItemsOrSlots(): void
    {
        $this->folder->write('artwork_42.png', 'old');
        $this->folder->write('artwork_420.jpg', 'another item');
        $this->folder->write('artwork_4_g1.jpg', 'another item');

        $this->upload('.jpg', 'new');

        self::assertSame(
            ['artwork_420.jpg' => 'another item', 'artwork_4_g1.jpg' => 'another item', 'artwork_42_g1.jpg' => 'new'],
            $this->folder->files,
        );
    }

    public function testAnUploadKeptByTheEnrichmentSnapshotSurvivesCleanup(): void
    {
        // Enrichment replaced the cover with a remote one, and "Remove
        // enrichment data" will restore the upload: it is still referenced.
        $this->upload('.jpg', 'mine');
        $this->row->setArtworkPath('https://i.discogs.com/a.jpg');
        $this->row->setOriginalArtworkPath('local');

        $this->writer()->tidy(42, $this->folder, ItemImageSlot::artwork());

        self::assertSame(['artwork_42_g1.jpg' => 'mine'], $this->folder->files);
    }

    public function testPhotoSlotsAreSeparate(): void
    {
        $this->folder->write('photo_42_2_g1.jpg', 'slot two');
        $this->writer()->write(
            42,
            'alice',
            $this->folder,
            ItemImageSlot::photo(1),
            static fn(MediaItem $item) => $item->setPhoto1Path('local'),
            '.jpg',
            'slot one',
        );

        self::assertSame(['photo_42_2_g1.jpg' => 'slot two', 'photo_42_1_g1.jpg' => 'slot one'], $this->folder->files);
        self::assertSame(1, $this->row->getPhoto1Rev());
        self::assertSame(0, $this->row->getPhoto2Rev());
    }

    public function testCleanupTakesTheRowLockAgainAndReadsTheRowUnderIt(): void
    {
        $this->upload('.jpg', 'new');

        self::assertSame(['lock', 'commit', 'lock', 'read', 'commit'], $this->log);
    }

    public function testAFailedWriteRollsBackWithThePreviousFileInPlace(): void
    {
        $this->upload('.png', 'old');
        $this->log = [];
        $this->folder->failWrites = true;

        try {
            $this->upload('.jpg', 'new');
            self::fail('The write failure must propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['artwork_42_g1.png' => 'old'], $this->folder->files);
        self::assertSame(['lock', 'rollback'], $this->log);
    }

    private function upload(string $ext, string $content): void
    {
        $this->writer()->write(
            42,
            'alice',
            $this->folder,
            ItemImageSlot::artwork(),
            static fn(MediaItem $item) => $item->setArtworkPath('local'),
            $ext,
            $content,
        );
    }

    private function remove(): void
    {
        $this->writer()->remove(
            42,
            'alice',
            $this->folder,
            ItemImageSlot::artwork(),
            static fn(MediaItem $item) => $item->setArtworkPath(null),
        );
    }

    private function writer(): ItemFileWriter
    {
        // The row as the database holds it: a change works on a copy, which
        // only replaces the row on update — and a rolled-back one never does.
        $mapper = $this->createMock(MediaItemMapper::class);
        $mapper->method('lockRow')->willReturnCallback(function (): void {
            $this->log[] = 'lock';
        });
        $mapper->method('findWritableForUser')->willReturnCallback(fn() => clone $this->row);
        $mapper->method('findById')->willReturnCallback(function () {
            $this->log[] = 'read';
            return clone $this->row;
        });
        $pending = null;
        $mapper->method('update')->willReturnCallback(function (MediaItem $item) use (&$pending) {
            $pending = $item;
            return $item;
        });

        $db = $this->createMock(IDBConnection::class);
        $db->method('commit')->willReturnCallback(function () use (&$pending): void {
            $this->log[] = 'commit';
            if ($pending !== null) {
                $this->row = $pending;
                $pending = null;
            }
            $next = array_shift($this->atCommit);
            if ($next !== null) {
                $next();
            }
        });
        $db->method('rollBack')->willReturnCallback(function () use (&$pending): void {
            $this->log[] = 'rollback';
            $pending = null;
        });

        return new ItemFileWriter($db, $mapper, $this->createStub(LoggerInterface::class));
    }
}
