<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\GdImageTrait;
use Psr\Log\LoggerInterface;

/**
 * Host for the trait under test. GdImageTrait's operations are private — they
 * are implementation shared by two controllers, not API — so the assertions
 * below reach them through a class that uses it, the same way the controllers
 * do.
 */
final class GdImageTraitHost
{
    use GdImageTrait;

    public function __construct(public readonly LoggerInterface $logger)
    {
    }

    public function strip(string $data, string $mime): ?string
    {
        return $this->stripImageMetadata($data, $mime);
    }

    public function animated(string $data): bool
    {
        return self::isAnimatedWebp($data);
    }

    public function budget(): int
    {
        return $this->decodePixelBudget();
    }

    public function withinBudget(string $data): bool
    {
        return $this->gdDimensionsWithinBudget($data);
    }

    public function limitBytes(): ?int
    {
        return self::memoryLimitBytes();
    }
}
