<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\CrateArtworkFiles;
use OCA\Crate\CrateImageHosts;
use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Dto\MediaItemData;
use OCA\Crate\Service\ActivityService;
use OCA\Crate\Service\MediaService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Two things about `artwork_path`: it is a free-form string a writer supplies,
 * and it owns files in appdata. So the value has to be constrained to something
 * the artwork endpoint can serve, and the files have to go when the item's
 * artwork source moves — else a superseded cover keeps occupying disk under an
 * item that no longer points at it.
 */
#[AllowMockObjectsWithoutExpectations]
class ArtworkPathTest extends TestCase
{
    private MediaItemMapper&MockObject $mapper;
    private ISimpleFolder&MockObject $folder;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(MediaItemMapper::class);
        $this->folder = $this->createMock(ISimpleFolder::class);
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function artworkPaths(): array
    {
        return [
            ['local', true],
            ['https://i.discogs.com/abc/R-1.jpg', true],
            ['https://covers.openlibrary.org/b/id/1-L.jpg', true],
            // Not an enrichment host: nothing proxies it, so nothing stores it.
            ['https://evil.example.com/x.jpg', false],
            // http would be fetched (and rendered) in cleartext.
            ['http://i.discogs.com/abc/R-1.jpg', false],
            ['javascript:alert(1)', false],
            ['data:image/svg+xml;base64,AAAA', false],
            ['/etc/passwd', false],
            ['LOCAL', false],
        ];
    }

    #[DataProvider('artworkPaths')]
    public function testArtworkPathShapes(string $path, bool $valid): void
    {
        self::assertSame($valid, CrateImageHosts::isValidArtworkPath($path));
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function redirectTargets(): array
    {
        return [
            // covers.openlibrary.org answers most requests with a 302 into the
            // archive, so the hop has to be followable or the cover is a blank
            // box in the browser and a 403 from the proxy.
            ['archive.org', true],
            ['ia600505.us.archive.org', true],
            ['covers.openlibrary.org', true],
            // Suffix matching must not be fooled by a lookalike domain.
            ['evilarchive.org', false],
            ['archive.org.evil.example', false],
            ['evil.example.com', false],
        ];
    }

    #[DataProvider('redirectTargets')]
    public function testRedirectTargetsAreAllowedButNotStorable(string $host, bool $followable): void
    {
        self::assertSame($followable, CrateImageHosts::isAllowedRedirectTarget($host));
        // A redirect target is somewhere a fetch may end up, never a value the
        // artwork column may hold.
        if (!CrateImageHosts::isAllowed($host)) {
            self::assertFalse(CrateImageHosts::isValidArtworkPath('https://' . $host . '/cover.jpg'));
        }
    }

    public function testThePageAllowsEveryHostAFetchCanReach(): void
    {
        $sources = CrateImageHosts::imageSources();

        self::assertContains('https://covers.openlibrary.org', $sources);
        self::assertContains('https://archive.org', $sources);
        // img-src is re-applied to each hop of a redirect, and the archive
        // serves the bytes from a per-datanode subdomain.
        self::assertContains('https://*.archive.org', $sources);
    }

    public function testUpdateRejectsAnArtworkPathNothingCanServe(): void
    {
        $this->mapper->method('findWritableForUser')->willReturn($this->item('https://i.discogs.com/a.jpg'));
        $this->mapper->expects(self::never())->method('update');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->update(5, 'alice', $this->data('https://evil.example.com/x.jpg'));
    }

    public function testAnUnchangedLegacyValueStillSaves(): void
    {
        // The edit form posts the item's current artwork back with every save,
        // so a row that predates the allowlist has to stay editable.
        $item = $this->item('http://old.example.com/legacy.jpg');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->folder->expects(self::never())->method('getDirectoryListing');

        $saved = $this->service()->update(5, 'alice', $this->data('http://old.example.com/legacy.jpg'));
        self::assertSame('http://old.example.com/legacy.jpg', $saved->getArtworkPath());
    }

    public function testReEnrichingToADifferentCoverDropsTheItemsFiles(): void
    {
        $item = $this->item('https://i.discogs.com/old.jpg');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);

        // Every file the item owns goes, whatever cover or extension it came
        // from — and nothing belonging to any other item does.
        $deleted = $this->listFolder([
            'artwork_5.jpg',
            'artwork_5.png',
            'artwork_5_1a2b3c4d5e6f7890.jpg',
            'artwork_50.jpg',
            'artwork_4_1a2b3c4d5e6f7890.webp',
            'photo_5_1.jpg',
        ]);

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/new.jpg']);

        self::assertSame(
            ['artwork_5.jpg', 'artwork_5.png', 'artwork_5_1a2b3c4d5e6f7890.jpg'],
            $deleted->names,
        );
    }

    public function testAnItemGivenItsFirstCoverDropsWhateverPrecededIt(): void
    {
        // An item with no artwork is the one that may have inherited a file
        // from an earlier occupant of its id, so this is exactly the case that
        // must not be skipped.
        $item = $this->item(null);
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $deleted = $this->listFolder(['artwork_5.jpg']);

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/new.jpg']);

        self::assertSame(['artwork_5.jpg'], $deleted->names);
    }

    public function testEnrichmentThatLeavesTheCoverAloneKeepsTheCache(): void
    {
        $item = $this->item('https://i.discogs.com/same.jpg');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->folder->expects(self::never())->method('getDirectoryListing');

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/same.jpg']);
    }

    public function testUploadedArtworkSurvivesEnrichment(): void
    {
        // 'local' is the user's own file, which stripEnrichment restores to.
        $item = $this->item('local');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->folder->expects(self::never())->method('getDirectoryListing');

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/new.jpg']);
    }

    public function testShareeCannotDeleteTheOwnersArtworkViaStripEnrichment(): void
    {
        $item = $this->item('https://i.discogs.com/old.jpg');
        $item->setOriginalArtworkPath(null);
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        // 'bob' holds a read/write share; removing alice's files is not an edit.
        $this->folder->expects(self::never())->method('getDirectoryListing');

        $this->service()->stripEnrichment(5, 'bob');
    }

    public function testOwnerStrippingEnrichmentStillClearsTheCachedCover(): void
    {
        $item = $this->item('https://i.discogs.com/old.jpg');
        $item->setOriginalArtworkPath(null);
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $deleted = $this->listFolder(['artwork_5_00ff00ff00ff00ff.jpg']);

        $this->service()->stripEnrichment(5, 'alice');

        self::assertSame(['artwork_5_00ff00ff00ff00ff.jpg'], $deleted->names);
    }

    public function testACacheEntryIsNamedAfterTheCoverItHolds(): void
    {
        // Two covers for the same item never share a file name, which is what
        // stops a stale entry being served in place of the current cover. The
        // extension is not part of the key — it comes from the fetched
        // Content-Type — so the name is compared without one.
        $one = CrateArtworkFiles::cachePrefix(5, 'https://i.discogs.com/old.jpg');
        $two = CrateArtworkFiles::cachePrefix(5, 'https://i.discogs.com/new.jpg');

        self::assertNotSame($one, $two);
        self::assertSame($one, CrateArtworkFiles::cachePrefix(5, 'https://i.discogs.com/old.jpg'));
        // And neither can be mistaken for another item's, nor for an upload.
        self::assertNotSame($one, CrateArtworkFiles::cachePrefix(50, 'https://i.discogs.com/old.jpg'));
        self::assertNotSame($one . '.jpg', CrateArtworkFiles::uploadName(5, '.jpg'));
    }

    /**
     * Stand the appdata folder up with $names in it, and hand back a recorder
     * of the names the code under test deletes.
     */
    private function listFolder(array $names): object
    {
        $recorder = new class {
            /** @var list<string> */
            public array $names = [];
        };

        $files = array_map(
            function (string $name) use ($recorder): ISimpleFile {
                $file = $this->createMock(ISimpleFile::class);
                $file->method('getName')->willReturn($name);
                $file->method('delete')->willReturnCallback(
                    static function () use ($recorder, $name): void {
                        $recorder->names[] = $name;
                    },
                );
                return $file;
            },
            $names,
        );
        $this->folder->method('getDirectoryListing')->willReturn($files);

        return $recorder;
    }

    private function item(?string $artworkPath): MediaItem
    {
        $item = new MediaItem();
        $item->setId(5);
        $item->setUserId('alice');
        $item->setTitle('Kind of Blue');
        $item->setArtworkPath($artworkPath);
        return $item;
    }

    private function data(?string $artworkPath): MediaItemData
    {
        return new MediaItemData('Kind of Blue', 'Miles Davis', 'LP', null, null, null, 'owned', null, $artworkPath);
    }

    private function service(): MediaService
    {
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturn($this->folder);
        $appDataFactory = $this->createMock(IAppDataFactory::class);
        $appDataFactory->method('get')->willReturn($appData);

        return new MediaService(
            $this->mapper,
            $this->createStub(PlaylistItemMapper::class),
            $this->createStub(CrateShareMapper::class),
            $this->createStub(PlaylistMapper::class),
            $appDataFactory,
            $this->createMock(IDBConnection::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(ActivityService::class),
            $this->createStub(IConfig::class),
        );
    }
}
