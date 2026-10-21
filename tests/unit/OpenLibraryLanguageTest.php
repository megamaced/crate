<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\OpenLibraryService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Open Library prefers an edition in the user's language. A row showing an
 * edition takes its title, cover, ISBN and publisher from that edition only,
 * never pairing one edition's title with another's cover or ISBN.
 */
#[AllowMockObjectsWithoutExpectations]
class OpenLibraryLanguageTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $queries = [];

    /** @var list<string> */
    private array $bodies = [];

    public function testAnEnglishUsersSearchIsUnchanged(): void
    {
        $this->bodies = [self::response(self::hobbit(edition: self::germanEdition()))];

        $row = $this->service('en')->search('the hobbit')[0];

        self::assertArrayNotHasKey('lang', $this->queries[0]);
        self::assertStringNotContainsString('editions', $this->queries[0]['fields']);
        self::assertSame('The Hobbit', $row['title']);
    }

    public function testASearchAsksForTheUsersLanguageAndItsEditionFields(): void
    {
        $this->bodies = [self::response(self::hobbit())];

        $this->service('de_DE')->search('the hobbit');

        self::assertSame('de', $this->queries[0]['lang']);
        self::assertStringContainsString('editions.title', $this->queries[0]['fields']);
        self::assertStringContainsString('editions.isbn', $this->queries[0]['fields']);
    }

    public function testAnEditionInTheLanguageShowsWithItsOwnCoverIsbnAndPublisher(): void
    {
        $this->bodies = [self::response(self::hobbit(edition: self::germanEdition()))];

        $row = $this->service('de')->search('the hobbit')[0];

        self::assertSame('Der Kleine Hobbit', $row['title']);
        self::assertSame('https://covers.openlibrary.org/b/id/14626130-L.jpg', $row['artworkUrl']);
        self::assertSame('9783423202770', $row['barcode']);
        self::assertSame('Deutscher Taschenbuch Verlag', $row['label']);
        self::assertTrue($row['coverFromEdition']);
        // The work's own facts stay the work's.
        self::assertSame('/works/OL27482W', $row['workKey']);
        self::assertSame('J.R.R. Tolkien', $row['artist']);
        self::assertSame(1937, $row['year']);
    }

    public function testAnEditionWithNoIsbnOrPublisherDoesNotBorrowTheWorks(): void
    {
        $edition = ['key' => '/books/OL1M', 'title' => 'Der kleine Hobbit', 'language' => ['ger'], 'cover_i' => 5];
        $this->bodies = [self::response(self::hobbit(edition: $edition))];

        $row = $this->service('de')->search('the hobbit')[0];

        self::assertNull($row['barcode']);
        self::assertNull($row['label']);
    }

    public function testAnEditionWithoutACoverIdIsAskedForByItsOwnId(): void
    {
        $edition = self::germanEdition();
        unset($edition['cover_i']);
        $this->bodies = [self::response(self::hobbit(edition: $edition))];

        $row = $this->service('de')->search('the hobbit')[0];

        self::assertSame('Der Kleine Hobbit', $row['title']);
        self::assertSame('https://covers.openlibrary.org/b/olid/OL9032793M-L.jpg', $row['artworkUrl']);
        self::assertSame('https://covers.openlibrary.org/b/olid/OL9032793M-M.jpg', $row['thumb']);
        self::assertTrue($row['coverFromEdition']);
    }

    public function testAnEditionWithNoWayToFindItsCoverShowsNone(): void
    {
        $edition = ['title' => 'Der kleine Hobbit', 'language' => ['ger']];
        $this->bodies = [self::response(self::hobbit(edition: $edition))];

        $row = $this->service('de')->search('the hobbit')[0];

        // Not the work's cover, which shows another edition.
        self::assertNull($row['artworkUrl']);
        self::assertNull($row['thumb']);
        self::assertTrue($row['coverFromEdition']);
    }

    public function testABokmalEditionIsFoundUnderEitherCode(): void
    {
        foreach (['nob', 'nor'] as $code) {
            $edition = ['key' => '/books/OL4M', 'title' => 'Hobbiten', 'language' => [$code], 'cover_i' => 7];
            $this->bodies = [self::response(self::hobbit(edition: $edition))];

            $row = $this->service('nb')->search('hobbit')[0];

            self::assertSame('Hobbiten', $row['title'], $code);
        }
        self::assertSame('nb', $this->queries[0]['lang']);
    }

    public function testAnEnglishEditionIsTheFallback(): void
    {
        $english = [
            'key' => '/books/OL2M', 'title' => 'The  Hobbit', 'language' => ['eng'],
            'cover_i' => 8406778, 'isbn' => ['0345445600'], 'publisher' => ['Ballantine Books'],
        ];
        $work = self::hobbit(edition: $english);
        $work['title'] = 'Хоббит';
        $this->bodies = [self::response($work)];

        $row = $this->service('de')->search('hobbit')[0];

        // Open Library's canonical title isn't necessarily English.
        self::assertSame('The Hobbit', $row['title']);
        self::assertSame('0345445600', $row['barcode']);
    }

    public function testAnEditionInAnotherLanguageIsIgnored(): void
    {
        $french = ['key' => '/books/OL3M', 'title' => 'Bilbo le Hobbit', 'language' => ['fre'], 'cover_i' => 9];
        $this->bodies = [self::response(self::hobbit(edition: $french))];

        $row = $this->service('de')->search('the hobbit')[0];

        self::assertSame('The Hobbit', $row['title']);
        self::assertSame('9780261102217', $row['barcode']);
        self::assertArrayNotHasKey('coverFromEdition', $row);
    }

    public function testReadAlikesPreferTheLanguageToo(): void
    {
        $this->bodies = [self::response(self::hobbit(edition: self::germanEdition()))];

        $rows = $this->service('de')->similarBySubject('Fantasy fiction', '/works/OL1W');

        self::assertSame('de', $this->queries[0]['lang']);
        self::assertSame('Der Kleine Hobbit', $rows[0]['title']);
    }

    public function testAnIsbnLookupIsUnchanged(): void
    {
        $this->bodies = [self::response(self::hobbit(edition: self::germanEdition()))];

        $row = $this->service('de')->searchByIsbn('9780261102217');

        self::assertArrayNotHasKey('lang', $this->queries[0]);
        self::assertSame('The Hobbit', $row['title']);
    }

    /** @return array<string, mixed> */
    private static function germanEdition(): array
    {
        return [
            'key' => '/books/OL9032793M',
            'title' => 'Der Kleine Hobbit',
            'cover_i' => 14626130,
            'language' => ['ger'],
            'publisher' => ['Deutscher Taschenbuch Verlag'],
            'isbn' => ['9783423202770', '3423202777'],
        ];
    }

    /**
     * @param array<string, mixed>|null $edition
     * @return array<string, mixed>
     */
    private static function hobbit(?array $edition = null): array
    {
        $doc = [
            'key' => '/works/OL27482W',
            'title' => 'The Hobbit',
            'author_name' => ['J.R.R. Tolkien'],
            'first_publish_year' => 1937,
            'cover_i' => 14627509,
            'publisher' => ['Allen & Unwin'],
            'isbn' => ['9780261102217'],
            'subject' => ['Fantasy fiction'],
        ];
        if ($edition !== null) {
            $doc['editions'] = ['numFound' => 1, 'start' => 0, 'docs' => [$edition]];
        }
        return $doc;
    }

    /** @param array<string, mixed> $doc */
    private static function response(array $doc): string
    {
        return (string)json_encode(['docs' => [$doc]]);
    }

    private function service(string $language): OpenLibraryService
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
            $this->queries[] = $options['query'] ?? [];
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn(array_shift($this->bodies) ?? '{}');
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);

        return new OpenLibraryService(
            $clients,
            $this->createMock(ICredentialsManager::class),
            $this->createMock(LoggerInterface::class),
            new FixedLanguage($language),
        );
    }
}
