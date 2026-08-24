<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

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
 * and the appdata cache file it produces is named after the item id alone. So
 * the value has to be constrained to something the artwork endpoint can serve,
 * and the cache has to be dropped when the item's artwork source moves — else
 * re-enriching to a different release keeps serving the first cover for good.
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
        $this->folder->expects(self::never())->method('getFile');

        $saved = $this->service()->update(5, 'alice', $this->data('http://old.example.com/legacy.jpg'));
        self::assertSame('http://old.example.com/legacy.jpg', $saved->getArtworkPath());
    }

    public function testReEnrichingToADifferentCoverDropsTheCachedFile(): void
    {
        $item = $this->item('https://i.discogs.com/old.jpg');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);

        // The cache file is keyed on the item id and extension only, so the
        // stale entry has to go for the new cover to ever be fetched.
        $deleted = [];
        $this->folder->method('getFile')->willReturnCallback(
            function (string $name) use (&$deleted): ISimpleFile {
                $file = $this->createStub(ISimpleFile::class);
                $deleted[] = $name;
                return $file;
            },
        );

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/new.jpg']);

        self::assertSame(
            ['artwork_5.jpg', 'artwork_5.png', 'artwork_5.webp', 'artwork_5.gif'],
            $deleted,
        );
    }

    public function testEnrichmentThatLeavesTheCoverAloneKeepsTheCache(): void
    {
        $item = $this->item('https://i.discogs.com/same.jpg');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->folder->expects(self::never())->method('getFile');

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/same.jpg']);
    }

    public function testUploadedArtworkSurvivesEnrichment(): void
    {
        // 'local' is the user's own file, which stripEnrichment restores to.
        $item = $this->item('local');
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->folder->expects(self::never())->method('getFile');

        $this->service()->applyReleaseData(5, 'alice', ['artworkUrl' => 'https://i.discogs.com/new.jpg']);
    }

    public function testShareeCannotDeleteTheOwnersArtworkViaStripEnrichment(): void
    {
        $item = $this->item('https://i.discogs.com/old.jpg');
        $item->setOriginalArtworkPath(null);
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        // 'bob' holds a read/write share; removing alice's files is not an edit.
        $this->folder->expects(self::never())->method('getFile');

        $this->service()->stripEnrichment(5, 'bob');
    }

    public function testOwnerStrippingEnrichmentStillClearsTheCachedCover(): void
    {
        $item = $this->item('https://i.discogs.com/old.jpg');
        $item->setOriginalArtworkPath(null);
        $this->mapper->method('findWritableForUser')->willReturn($item);
        $this->mapper->method('update')->willReturnArgument(0);
        $this->folder->expects(self::exactly(4))
            ->method('getFile')
            ->willThrowException(new NotFoundException('gone'));

        $this->service()->stripEnrichment(5, 'alice');
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
