<?php

declare(strict_types=1);

namespace OCA\Crate\Exception;

/**
 * Thrown when the Discogs API responds with HTTP 429 Too Many Requests.
 */
class DiscogsRateLimitException extends \RuntimeException {}
