<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\EnrichmentLanguage;

/** An EnrichmentLanguage for one account language, with no session behind it. */
final class FixedLanguage extends EnrichmentLanguage
{
    public function __construct(private readonly string $code = 'en')
    {
    }

    public function accountLanguage(): string
    {
        return $this->code;
    }
}
