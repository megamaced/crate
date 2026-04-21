<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Db\MediaItem;
use OCA\Crate\Service\EnrichmentResult;
use PHPUnit\Framework\TestCase;

class EnrichmentResultTest extends TestCase
{
    public function testOkHoldsItemAndStatus200(): void
    {
        $item   = new MediaItem();
        $result = EnrichmentResult::ok($item);

        self::assertTrue($result->isOk());
        self::assertSame($item, $result->item);
        self::assertNull($result->error);
        self::assertSame(200, $result->status);
    }

    public function testErrorHoldsMessageAndStatusAndNoItem(): void
    {
        $result = EnrichmentResult::error('Not found', 404);

        self::assertFalse($result->isOk());
        self::assertNull($result->item);
        self::assertSame('Not found', $result->error);
        self::assertSame(404, $result->status);
    }
}
