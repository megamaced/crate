<?php

declare(strict_types=1);

namespace OCA\Crate\Exception;

/**
 * Thrown by a provider's suggestion lookup when it cannot be made for this
 * user — the provider needs a credential they have not set. Unlike an empty
 * answer, this says nothing about the item, so it must not be cached as one.
 */
class RecommendationUnavailableException extends \RuntimeException
{
}
