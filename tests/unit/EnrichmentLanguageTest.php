<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\EnrichmentLanguage;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which language each provider is asked for, given a Nextcloud language code.
 * English, and anything a provider can't serve, is null: the provider is then
 * asked exactly what it was asked before languages.
 */
class EnrichmentLanguageTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: ?string}> */
    public static function codes(): array
    {
        // Nextcloud code => [TMDB, Open Library]
        return [
            'bare German'                 => ['de', 'de-DE', 'de'],
            'formal German'               => ['de_DE', 'de-DE', 'de'],
            'hyphenated'                  => ['de-DE', 'de-DE', 'de'],
            'odd case'                    => ['DE-de', 'de-DE', 'de'],
            'Austrian German'             => ['de_AT', 'de-AT', 'de'],
            'Brazilian Portuguese'        => ['pt_BR', 'pt-BR', 'pt'],
            'European Portuguese'         => ['pt-PT', 'pt-PT', 'pt'],
            'bare Portuguese'             => ['pt', 'pt-PT', 'pt'],
            'traditional Chinese'         => ['zh_TW', 'zh-TW', 'zh'],
            'simplified Chinese'          => ['zh_CN', 'zh-CN', 'zh'],
            'Norwegian Bokmål'            => ['nb', 'nb-NO', 'nb'],
            'Serbian'                     => ['sr', 'sr-RS', 'sr'],
            'region TMDB lacks'           => ['es_EC', 'es-ES', 'es'],
            'numeric region'              => ['es_419', 'es-ES', 'es'],
            // TMDB's Serbian is Cyrillic, and neither provider labels a script.
            'Latin-script Serbian'        => ['sr@latin', null, null],
            'English'                     => ['en', null, null],
            'British English'             => ['en_GB', null, null],
            'language TMDB lacks'         => ['is', null, 'is'],
            'language neither has'        => ['ast', null, null],
            'unknown'                     => ['xx', null, null],
            'empty'                       => ['', null, null],
            'not a code'                  => ['de DE!', null, null],
        ];
    }

    #[DataProvider('codes')]
    public function testEachProviderIsAskedForWhatItCanServe(string $code, ?string $tmdb, ?string $openLibrary): void
    {
        self::assertSame($tmdb, EnrichmentLanguage::tmdbCode($code));
        self::assertSame($openLibrary, EnrichmentLanguage::openLibraryCode($code));
    }

    public function testTheLanguageIsTheSignedInUsersAccountLanguage(): void
    {
        $user = $this->createStub(IUser::class);
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        $factory = $this->createMock(IFactory::class);
        $factory->expects(self::once())->method('getUserLanguage')->with($user)->willReturn('fr');

        $language = new EnrichmentLanguage($factory, $session);

        self::assertSame('fr-FR', $language->tmdb());
        self::assertSame('fr', $language->openLibrary());
    }

    public function testWithNobodySignedInItIsEnglish(): void
    {
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn(null);

        $language = new EnrichmentLanguage($this->createStub(IFactory::class), $session);

        self::assertNull($language->tmdb());
        self::assertNull($language->openLibrary());
    }
}
