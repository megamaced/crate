<?php

declare(strict_types=1);

namespace OCA\Crate\Exception;

/**
 * Thrown when a PriceCharting call cannot be made within the provider's
 * one-call-per-second limit: the credential's queue is too long to wait out
 * in one request, or PriceCharting has started refusing it.
 */
class PriceChartingRateLimitException extends \RuntimeException
{
}
