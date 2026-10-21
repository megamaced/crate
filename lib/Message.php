<?php

declare(strict_types=1);

namespace OCA\Crate;

use OCP\IL10N;

/**
 * A message for the user, kept untranslated until it is shown.
 *
 * A service says what happened without knowing who will read it. Whatever
 * turns the message into a response translates it for whoever asked, so
 * nothing the services produce, store or cache depends on a language. The
 * constructors are named t() and n() because the translation tooling collects
 * the strings passed to calls of those names.
 */
final class Message implements \Stringable
{
    /**
     * @param list<string|int|float|Message> $parameters printf-style, in order;
     *                                                    a Message is translated too
     */
    private function __construct(
        private string $text,
        private array $parameters,
        private ?string $plural,
        private int $count,
    ) {
    }

    /** @param list<string|int|float|Message> $parameters */
    public static function t(string $text, array $parameters = []): self
    {
        return new self($text, $parameters, null, 0);
    }

    /**
     * $count picks the plural form and replaces %n.
     *
     * @param list<string|int|float|Message> $parameters
     */
    public static function n(string $singular, string $plural, int $count, array $parameters = []): self
    {
        return new self($singular, $parameters, $plural, $count);
    }

    public function translate(IL10N $l): string
    {
        $parameters = array_map(
            static fn (string|int|float|Message $parameter) => $parameter instanceof self
                ? $parameter->translate($l)
                : $parameter,
            $this->parameters,
        );

        return $this->plural === null
            ? $l->t($this->text, $parameters)
            : $l->n($this->text, $this->plural, $this->count, $parameters);
    }

    /** The English text, for logs. */
    public function __toString(): string
    {
        $text = $this->plural !== null && $this->count !== 1 ? $this->plural : $this->text;
        if ($this->plural !== null) {
            $text = str_replace('%n', (string) $this->count, $text);
        }

        return $this->parameters === []
            ? $text
            : vsprintf($text, array_map(static fn ($parameter) => (string) $parameter, $this->parameters));
    }
}
