<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCP\IL10N;

/**
 * The IL10N Nextcloud gives a language with no translations: the English text,
 * with its parameters filled in the same way.
 */
final class EnglishL10N implements IL10N
{
    public function t(string $text, $parameters = []): string
    {
        return $parameters === [] ? $text : vsprintf($text, (array) $parameters);
    }

    public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string
    {
        $text = str_replace('%n', (string) $count, $count === 1 ? $text_singular : $text_plural);
        return $this->t($text, $parameters);
    }

    public function l(string $type, $data, array $options = [])
    {
        return (string) $data;
    }

    public function getLanguageCode(): string
    {
        return 'en';
    }

    public function getLocaleCode(): string
    {
        return 'en';
    }
}
