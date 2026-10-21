<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\CrateShareMapper;
use OCA\Crate\Db\MediaItem;
use OCA\Crate\Db\MediaItemMapper;
use OCA\Crate\Db\PlaylistItemMapper;
use OCA\Crate\Db\PlaylistMapper;
use OCA\Crate\Service\ActivityService;
use OCA\Crate\Service\ComicVineService;
use OCA\Crate\Service\DiscogsService;
use OCA\Crate\Service\EnrichmentService;
use OCA\Crate\Service\MediaService;
use OCA\Crate\Service\OpenLibraryService;
use OCA\Crate\Service\RawgService;
use OCA\Crate\Service\TmdbService;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What enrichment stores when the data comes in the user's language: an
 * edition's cover stays with its title, a linked book keeps the cover it was
 * added with, and a read/write sharee's enrichment changes the one item every
 * viewer sees.
 */
#[AllowMockObjectsWithoutExpectations]
class EnrichmentInLanguageTest extends TestCase
{
    private const EDITION_COVER = 'https://covers.openlibrary.org/b/id/14626130-L.jpg';
    private const WORK_COVER = 'https://covers.openlibrary.org/b/id/14627509-L.jpg';

    private MediaItem $item;

    protected function setUp(): void
    {
        $this->item = new MediaItem();
        $this->item->setId(1);
        $this->item->setUserId('alice');
        $this->item->setTitle('Der kleine Hobbit');
        $this->item->setArtist('J.R.R. Tolkien');
        $this->item->setFormat('Paperback');
        $this->item->setCategory('book');
    }

    public function testAnEditionsCoverIsKeptOverTheWorks(): void
    {
        $doc = ['title' => 'Der Kleine Hobbit', 'artworkUrl' => self::EDITION_COVER, 'coverFromEdition' => true];

        $this->mediaService()->applyOpenLibraryData(1, 'alice', $doc, ['artworkUrl' => self::WORK_COVER]);

        self::assertSame(self::EDITION_COVER, $this->item->getArtworkPath());
    }

    public function testAnEditionWithoutACoverDoesNotTakeTheWorks(): void
    {
        $doc = ['title' => 'Der Kleine Hobbit', 'artworkUrl' => null, 'coverFromEdition' => true];

        $this->mediaService()->applyOpenLibraryData(1, 'alice', $doc, ['artworkUrl' => self::WORK_COVER]);

        self::assertNull($this->item->getArtworkPath());
        self::assertSame('Der Kleine Hobbit', $this->item->getTitle());
    }

    public function testAWorkRowStillTakesTheWorksCover(): void
    {
        $doc = ['title' => 'The Hobbit', 'artworkUrl' => self::EDITION_COVER];

        $this->mediaService()->applyOpenLibraryData(1, 'alice', $doc, ['artworkUrl' => self::WORK_COVER]);

        self::assertSame(self::WORK_COVER, $this->item->getArtworkPath());
    }

    public function testALinkedBookKeepsTheCoverItWasAddedWith(): void
    {
        $this->item->setDiscogsId('/works/OL27482W');
        $this->item->setArtworkPath(self::EDITION_COVER);
        $openLibrary = $this->createStub(OpenLibraryService::class);
        $openLibrary->method('getWork')->willReturn([
            'artworkUrl' => self::WORK_COVER,
            'overview' => 'A hobbit goes on an adventure.',
        ]);

        $result = $this->enrichment(openLibrary: $openLibrary)->enrich(1, 'alice');

        self::assertNull($result->error);
        self::assertSame(self::EDITION_COVER, $this->item->getArtworkPath());
        self::assertSame('A hobbit goes on an adventure.', $this->item->getPressingNotes());
    }

    public function testALinkedBookWithoutACoverGetsTheWorks(): void
    {
        $this->item->setDiscogsId('/works/OL27482W');
        $openLibrary = $this->createStub(OpenLibraryService::class);
        $openLibrary->method('getWork')->willReturn(['artworkUrl' => self::WORK_COVER]);

        $this->enrichment(openLibrary: $openLibrary)->enrich(1, 'alice');

        self::assertSame(self::WORK_COVER, $this->item->getArtworkPath());
    }

    public function testAReadWriteShareesEnrichmentStoresTheirLanguageOnTheOneItem(): void
    {
        // alice's film, enriched by bob, who reads German.
        $this->item->setCategory('film');
        $this->item->setTitle('Amelie');
        $this->item->setDiscogsId('194');
        $tmdb = $this->tmdb('de', [
            'id' => 194,
            'title' => 'Amélie',
            'original_title' => "Le Fabuleux Destin d'Amélie Poulain",
            'original_language' => 'fr',
            'overview' => 'English overview.',
            'translations' => ['translations' => [[
                'iso_639_1' => 'de',
                'iso_3166_1' => 'DE',
                'data' => ['title' => 'Die fabelhafte Welt der Amélie', 'overview' => 'Deutsch.'],
            ]]],
        ]);

        $result = $this->enrichment(tmdb: $tmdb)->enrich(1, 'bob');

        self::assertNull($result->error);
        self::assertSame('alice', $this->item->getUserId());
        self::assertSame('Die fabelhafte Welt der Amélie', $this->item->getTitle());
        // The title it had before, for undoing the enrichment.
        self::assertSame('Amelie', $this->item->getOriginalTitle());
    }

    private function enrichment(
        ?OpenLibraryService $openLibrary = null,
        ?TmdbService $tmdb = null,
    ): EnrichmentService {
        return new EnrichmentService(
            $this->mediaService(),
            $this->createStub(DiscogsService::class),
            $tmdb ?? $this->createStub(TmdbService::class),
            $openLibrary ?? $this->createStub(OpenLibraryService::class),
            $this->createStub(RawgService::class),
            $this->createStub(ComicVineService::class),
        );
    }

    private function mediaService(): MediaService
    {
        $mapper = $this->createMock(MediaItemMapper::class);
        // alice owns the item; bob holds a read/write share of it.
        $mapper->method('findWritableForUser')->willReturnCallback(fn() => $this->item);
        $mapper->method('update')->willReturnArgument(0);

        return new MediaService(
            $mapper,
            $this->createStub(PlaylistItemMapper::class),
            $this->createStub(CrateShareMapper::class),
            $this->createStub(PlaylistMapper::class),
            $this->createStub(IAppDataFactory::class),
            $this->createStub(IDBConnection::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(ActivityService::class),
            $this->createStub(IConfig::class),
        );
    }

    /** @param array<string, mixed> $movie the details response */
    private function tmdb(string $language, array $movie): TmdbService
    {
        $client = $this->createStub(IClient::class);
        $client->method('get')->willReturnCallback(function () use ($movie): IResponse {
            $response = $this->createStub(IResponse::class);
            $response->method('getBody')->willReturn((string)json_encode($movie));
            return $response;
        });
        $clients = $this->createStub(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        $credentials = $this->createStub(ICredentialsManager::class);
        $credentials->method('retrieve')->willReturn('token');

        return new TmdbService(
            $clients,
            $credentials,
            $this->createStub(LoggerInterface::class),
            new FixedLanguage($language),
        );
    }
}
