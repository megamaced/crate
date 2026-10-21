<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * The language enrichment asks its providers for: the Nextcloud account
 * language of the user making the request, as Nextcloud resolves it — the
 * server's forced language, the account setting, the request's
 * Accept-Language, the server default, then English. It is not the language
 * of the device or of the Android app.
 *
 * Only two providers can answer in another language. TMDB translates film
 * titles and overviews; Open Library prefers an edition of a book in the
 * language. RAWG, ComicVine and Discogs have no such option and stay as they
 * are. English, and any language a provider can't serve, reads as null here:
 * the provider is then asked exactly what it was asked before languages.
 */
class EnrichmentLanguage
{
    /**
     * TMDB's language-region pairs (configuration/primary_translations),
     * English aside. TMDB wants a region with every language.
     */
    private const TMDB_CODES = [
        'ar-AE', 'ar-SA', 'be-BY', 'bg-BG', 'ca-ES', 'cs-CZ', 'da-DK', 'de-AT',
        'de-CH', 'de-DE', 'el-GR', 'es-ES', 'es-MX', 'et-EE', 'eu-ES', 'fa-IR',
        'fi-FI', 'fr-CA', 'fr-FR', 'ga-IE', 'gl-ES', 'he-IL', 'hr-HR', 'hu-HU',
        'id-ID', 'it-IT', 'ja-JP', 'ka-GE', 'ko-KR', 'lt-LT', 'lv-LV', 'nb-NO',
        'nl-BE', 'nl-NL', 'pl-PL', 'pt-BR', 'pt-PT', 'ro-RO', 'ru-RU', 'sk-SK',
        'sl-SI', 'sr-RS', 'sv-SE', 'th-TH', 'tr-TR', 'uk-UA', 'vi-VN', 'zh-CN',
        'zh-HK', 'zh-SG', 'zh-TW',
    ];

    /** The region a language without one is asked for: its own country's. */
    public const TMDB_PRIMARY_REGION = [
        'ar' => 'SA', 'be' => 'BY', 'bg' => 'BG', 'ca' => 'ES', 'cs' => 'CZ',
        'da' => 'DK', 'de' => 'DE', 'el' => 'GR', 'es' => 'ES', 'et' => 'EE',
        'eu' => 'ES', 'fa' => 'IR', 'fi' => 'FI', 'fr' => 'FR', 'ga' => 'IE',
        'gl' => 'ES', 'he' => 'IL', 'hr' => 'HR', 'hu' => 'HU', 'id' => 'ID',
        'it' => 'IT', 'ja' => 'JP', 'ka' => 'GE', 'ko' => 'KR', 'lt' => 'LT',
        'lv' => 'LV', 'nb' => 'NO', 'nl' => 'NL', 'pl' => 'PL', 'pt' => 'PT',
        'ro' => 'RO', 'ru' => 'RU', 'sk' => 'SK', 'sl' => 'SI', 'sr' => 'RS',
        'sv' => 'SE', 'th' => 'TH', 'tr' => 'TR', 'uk' => 'UA', 'vi' => 'VN',
        'zh' => 'CN',
    ];

    /**
     * Open Library's language codes (MARC 21) by ISO 639-1: Open Library takes
     * the ISO code to prefer an edition, and labels the edition it returns
     * with the MARC one. Bokmål editions are labelled "nob", or "nor" (plain
     * Norwegian) on older records.
     */
    private const MARC_CODES = [
        'ar' => 'ara', 'be' => 'bel', 'bg' => 'bul', 'ca' => 'cat', 'cs' => 'cze',
        'da' => 'dan', 'de' => 'ger', 'el' => 'gre', 'es' => 'spa', 'et' => 'est',
        'eu' => 'baq', 'fa' => 'per', 'fi' => 'fin', 'fr' => 'fre', 'ga' => 'gle',
        'gl' => 'glg', 'he' => 'heb', 'hr' => 'hrv', 'hu' => 'hun', 'id' => 'ind',
        'is' => 'ice', 'it' => 'ita', 'ja' => 'jpn', 'ka' => 'geo', 'ko' => 'kor',
        'lt' => 'lit', 'lv' => 'lav', 'mk' => 'mac', 'nb' => ['nob', 'nor'], 'nl' => 'dut',
        'pl' => 'pol', 'pt' => 'por', 'ro' => 'rum', 'ru' => 'rus', 'sk' => 'slo',
        'sl' => 'slv', 'sr' => 'srp', 'sv' => 'swe', 'th' => 'tha', 'tr' => 'tur',
        'uk' => 'ukr', 'vi' => 'vie', 'zh' => 'chi',
    ];

    /** Open Library's code for an English edition. */
    public const ENGLISH_MARC = 'eng';

    private ?string $account = null;

    public function __construct(
        private readonly IFactory $l10nFactory,
        private readonly IUserSession $userSession,
    ) {
    }

    /** The requesting user's Nextcloud language code: "de", "pt_BR", "sr@latin"… */
    public function accountLanguage(): string
    {
        if ($this->account === null) {
            $user = $this->userSession->getUser();
            $this->account = $user !== null ? $this->l10nFactory->getUserLanguage($user) : 'en';
        }
        return $this->account;
    }

    /** TMDB's language-region code for the user ("de-DE"), or null for English. */
    public function tmdb(): ?string
    {
        return self::tmdbCode($this->accountLanguage());
    }

    /** The ISO 639-1 code to ask Open Library for ("de"), or null for English. */
    public function openLibrary(): ?string
    {
        return self::openLibraryCode($this->accountLanguage());
    }

    /**
     * TMDB's code for a Nextcloud language: the exact language-region pair
     * when TMDB has it, else the language's own region, else null.
     */
    public static function tmdbCode(string $language): ?string
    {
        $parsed = self::parse($language);
        if ($parsed === null) {
            return null;
        }
        [$lang, $region] = $parsed;
        if ($region !== null && in_array("$lang-$region", self::TMDB_CODES, true)) {
            return "$lang-$region";
        }
        $primary = self::TMDB_PRIMARY_REGION[$lang] ?? null;
        return $primary !== null ? "$lang-$primary" : null;
    }

    /** Open Library's ISO 639-1 code for a Nextcloud language, or null. */
    public static function openLibraryCode(string $language): ?string
    {
        $parsed = self::parse($language);
        if ($parsed === null) {
            return null;
        }
        return isset(self::MARC_CODES[$parsed[0]]) ? $parsed[0] : null;
    }

    /**
     * The MARC codes an Open Library edition in a language may carry, for the
     * ISO 639-1 code it was asked for; empty for a language it has no code for.
     *
     * @return list<string>
     */
    public static function marcCodes(string $iso): array
    {
        $codes = self::MARC_CODES[$iso] ?? [];
        return is_array($codes) ? $codes : [$codes];
    }

    /**
     * Split a language code into its language and region, accepting either
     * separator and any case ("de_DE", "de-de"). Null for English, for a code
     * that isn't one, and for a script variant ("sr@latin"): no provider here
     * labels a script, so treating it as the plain language would hand a
     * Latin-script reader Cyrillic.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private static function parse(string $language): ?array
    {
        $language = trim($language);
        if (str_contains($language, '@')) {
            return null;
        }
        if (preg_match('/^([A-Za-z]{2,3})(?:[_-]([A-Za-z]{2}|[0-9]{3}))?$/', $language, $m) !== 1) {
            return null;
        }
        $lang = strtolower($m[1]);
        if ($lang === 'en') {
            return null;
        }
        $region = isset($m[2]) && ctype_alpha($m[2]) ? strtoupper($m[2]) : null;
        return [$lang, $region];
    }
}
