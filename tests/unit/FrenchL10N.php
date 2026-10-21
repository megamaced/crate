<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCP\IL10N;

/**
 * An IL10N with a handful of French translations, enough to tell a translated
 * message from one that slipped through in English.
 */
final class FrenchL10N implements IL10N
{
    private const TRANSLATIONS = [
        'Row %1$s: %2$s — skipped' => 'Ligne %1$s : %2$s — ignorée',
        'purchase price requires a currency' => 'le prix d’achat exige une devise',
        'Already shared with this user.' => 'Déjà partagé avec cet utilisateur.',
        'No TMDB ID available.' => 'Aucun identifiant TMDB disponible.',
    ];

    private const PLURALS = [
        '%n row skipped' => ['%n ligne ignorée', '%n lignes ignorées'],
    ];

    public function t(string $text, $parameters = []): string
    {
        $text = self::TRANSLATIONS[$text] ?? $text;
        return $parameters === [] ? $text : vsprintf($text, (array) $parameters);
    }

    public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string
    {
        $forms = self::PLURALS[$text_singular] ?? [$text_singular, $text_plural];
        // French uses the singular for 0 and 1.
        $text = str_replace('%n', (string) $count, $count > 1 ? $forms[1] : $forms[0]);
        return $parameters === [] ? $text : vsprintf($text, $parameters);
    }

    public function l(string $type, $data, array $options = [])
    {
        return (string) $data;
    }

    public function getLanguageCode(): string
    {
        return 'fr';
    }

    public function getLocaleCode(): string
    {
        return 'fr_FR';
    }
}
