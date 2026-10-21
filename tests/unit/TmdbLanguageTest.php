<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\TmdbService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TMDB in the user's language. Lists ask for the language outright; a film's
 * details are asked for in English and overlaid with the user's translation,
 * so a missing translation leaves English rather than another language. An
 * English user's requests are what they were before languages.
 */
#[AllowMockObjectsWithoutExpectations]
class TmdbLanguageTest extends TestCase
{
    /** @var list<array{url: string, query: array<string, string>}> */
    private array $requests = [];

    /** @var list<string> */
    private array $bodies = [];

    public function testAnEnglishUsersRequestsAreUnchanged(): void
    {
        $this->bodies = ['{"results":[]}', json_encode(self::film())];
        $tmdb = $this->service('en');

        $tmdb->search('alice', 'Amelie');
        $tmdb->getMovie('alice', '194');

        self::assertSame(['query' => 'Amelie', 'language' => 'en-US', 'page' => '1'], $this->requests[0]['query']);
        self::assertSame(['language' => 'en-US', 'append_to_response' => 'credits'], $this->requests[1]['query']);
    }

    public function testListsAskForTheUsersLanguage(): void
    {
        $this->bodies = ['{"results":[]}', '{"results":[{"id":1,"title":"Die fabelhafte Welt der Amélie"}]}'];
        $tmdb = $this->service('pt_BR');

        $tmdb->search('alice', 'Amelie');
        $tmdb->similar('alice', '194');

        self::assertSame('pt-BR', $this->requests[0]['query']['language']);
        self::assertSame('pt-BR', $this->requests[1]['query']['language']);
    }

    public function testDetailsAreEnglishWithTheTranslationOnTop(): void
    {
        $this->bodies = [json_encode(self::film(translations: [
            self::translation('de', 'DE', 'Die fabelhafte Welt der Amélie', 'Amélie lebt in Paris.'),
        ]))];

        $movie = $this->service('de')->getMovie('alice', '194');

        self::assertSame(
            ['language' => 'en-US', 'append_to_response' => 'credits,translations'],
            $this->requests[0]['query'],
        );
        self::assertSame('Die fabelhafte Welt der Amélie', $movie['title']);
        self::assertSame('Amélie lebt in Paris.', $movie['overview']);
        // Genres stay English: filters and suggestions match on them.
        self::assertSame('Comedy, Romance', $movie['genres']);
        self::assertSame('Jean-Pierre Jeunet', $movie['artist']);
    }

    public function testABlankTranslatedTitleIsTheOriginalTitle(): void
    {
        // TMDB leaves the title blank when the translation keeps the original.
        $movie = TmdbService::localise(
            ['title' => 'Amélie', 'overview' => 'English overview.'],
            self::film(translations: [self::translation('de', 'DE', '', 'Deutsche Beschreibung.')]),
            'de-DE',
        );

        self::assertSame("Le Fabuleux Destin d'Amélie Poulain", $movie['title']);
        self::assertSame('Deutsche Beschreibung.', $movie['overview']);
    }

    public function testATranslationWithoutAnOverviewKeepsTheEnglishOne(): void
    {
        $movie = TmdbService::localise(
            ['title' => 'Amélie', 'overview' => 'English overview.'],
            self::film(translations: [self::translation('de', 'DE', 'Die fabelhafte Welt der Amélie', '')]),
            'de-DE',
        );

        self::assertSame('Die fabelhafte Welt der Amélie', $movie['title']);
        self::assertSame('English overview.', $movie['overview']);
    }

    public function testWithNoTranslationAFilmStaysEnglish(): void
    {
        $movie = TmdbService::localise(
            ['title' => 'Amélie', 'overview' => 'English overview.'],
            self::film(translations: [self::translation('fr', 'FR', '', 'Résumé.')]),
            'de-DE',
        );

        self::assertSame('Amélie', $movie['title']);
        self::assertSame('English overview.', $movie['overview']);
    }

    public function testWithNoTranslationAFilmMadeInTheLanguageKeepsItsOwnTitle(): void
    {
        $movie = TmdbService::localise(
            ['title' => 'The Boat', 'overview' => 'English overview.'],
            ['original_title' => 'Das Boot', 'original_language' => 'de', 'translations' => ['translations' => []]],
            'de-DE',
        );

        self::assertSame('Das Boot', $movie['title']);
    }

    public function testAnotherRegionsTranslationBeatsEnglish(): void
    {
        $movie = TmdbService::localise(
            ['title' => 'Amélie', 'overview' => 'English overview.'],
            self::film(translations: [self::translation('pt', 'PT', 'O Fabuloso Destino de Amélie', 'Resumo.')]),
            'pt-BR',
        );

        self::assertSame('O Fabuloso Destino de Amélie', $movie['title']);
    }

    public function testTheUsersRegionWinsThenTheLanguagesOwnCountry(): void
    {
        $film = self::film(translations: [
            self::translation('de', 'CH', 'Schweizer Titel', ''),
            self::translation('de', 'DE', 'Deutscher Titel', ''),
            self::translation('de', 'AT', 'Österreichischer Titel', ''),
        ]);

        self::assertSame('Österreichischer Titel', TmdbService::localise(['title' => 'x'], $film, 'de-AT')['title']);
        self::assertSame('Deutscher Titel', TmdbService::localise(['title' => 'x'], $film, 'de-DE')['title']);
    }

    /**
     * @param list<array<string, mixed>> $translations
     * @return array<string, mixed>
     */
    private static function film(array $translations = []): array
    {
        return [
            'id' => 194,
            'title' => 'Amélie',
            'original_title' => "Le Fabuleux Destin d'Amélie Poulain",
            'original_language' => 'fr',
            'overview' => 'English overview.',
            'release_date' => '2001-04-25',
            'genres' => [['id' => 35, 'name' => 'Comedy'], ['id' => 10749, 'name' => 'Romance']],
            'credits' => ['crew' => [['job' => 'Director', 'name' => 'Jean-Pierre Jeunet', 'id' => 2419]]],
            'translations' => ['translations' => $translations],
        ];
    }

    /** @return array<string, mixed> */
    private static function translation(string $lang, string $region, string $title, string $overview): array
    {
        return [
            'iso_639_1' => $lang,
            'iso_3166_1' => $region,
            'data' => ['title' => $title, 'overview' => $overview, 'tagline' => ''],
        ];
    }

    private function service(string $language): TmdbService
    {
        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
            $this->requests[] = ['url' => $url, 'query' => $options['query'] ?? []];
            $response = $this->createMock(IResponse::class);
            $response->method('getBody')->willReturn(array_shift($this->bodies) ?? '{}');
            return $response;
        });
        $clients = $this->createMock(IClientService::class);
        $clients->method('newClient')->willReturn($client);
        $credentials = $this->createMock(ICredentialsManager::class);
        $credentials->method('retrieve')->willReturn('token');

        return new TmdbService(
            $clients,
            $credentials,
            $this->createMock(LoggerInterface::class),
            new FixedLanguage($language),
        );
    }
}
