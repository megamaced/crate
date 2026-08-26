<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\ExportController;
use OCA\Crate\Controller\ImportController;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * One export reads a user's entire collection and assembles the file in
 * memory, which is the weight of an import commit rather than of a lookup —
 * so it carries the same budget. The attribute is the only place that limit
 * exists, and losing it is invisible until someone holds the endpoint open,
 * so the pair is pinned together here.
 */
class ExportRateLimitTest extends TestCase
{
    public function testExportIsRateLimitedLikeAnImportCommit(): void
    {
        $export = $this->userRateLimit(ExportController::class, 'export');
        $commit = $this->userRateLimit(ImportController::class, 'commit');

        self::assertSame($commit->getLimit(), $export->getLimit());
        self::assertSame($commit->getPeriod(), $export->getPeriod());
    }

    /**
     * @param class-string $controller
     */
    private function userRateLimit(string $controller, string $method): UserRateLimit
    {
        $attributes = (new \ReflectionMethod($controller, $method))
            ->getAttributes(UserRateLimit::class);

        self::assertCount(1, $attributes, "{$controller}::{$method} must carry a UserRateLimit");
        return $attributes[0]->newInstance();
    }
}
