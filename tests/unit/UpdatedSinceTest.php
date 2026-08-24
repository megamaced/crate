<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\MediaController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The delta-sync cursor a client sends lands in a SQL timestamp comparison.
 * PostgreSQL rejects the whole statement on anything it cannot parse, so an
 * unusable cursor has to be caught here and answered with a 400 — not passed
 * down to fail the query.
 */
class UpdatedSinceTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function rejected(): array
    {
        return [
            ['yesterday'],
            ['now'],
            [''],
            ['   '],
            ['2026-13-99'],
            ['2026-02-30'],
            ['1 OR 1=1'],
            ["2026-08-01'; DROP TABLE oc_crate_media_items; --"],
            ['0'],
            ['1755000000'],
        ];
    }

    #[DataProvider('rejected')]
    public function testUnusableCursorsAreRejected(string $value): void
    {
        self::assertNull(MediaController::normaliseUpdatedSince($value));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function accepted(): array
    {
        return [
            ['2026-08-01', '2026-08-01 00:00:00'],
            ['2026-08-01 12:34:56', '2026-08-01 12:34:56'],
            ['  2026-08-01 12:34:56  ', '2026-08-01 12:34:56'],
            ['2026-08-01T12:34', '2026-08-01 12:34:00'],
            ['2026-08-01T12:34:56', '2026-08-01 12:34:56'],
        ];
    }

    #[DataProvider('accepted')]
    public function testClientShapesNormaliseToTheStoredFormat(string $value, string $expected): void
    {
        self::assertSame($expected, MediaController::normaliseUpdatedSince($value));
    }

    public function testZoneBearingCursorsAreConvertedToTheServersOwnZone(): void
    {
        // Rows are stamped with the server's local time and no zone, so an
        // offset in the request has to be resolved against that same zone.
        $expected = (new \DateTimeImmutable('2026-08-01T12:00:00+02:00'))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');

        self::assertSame($expected, MediaController::normaliseUpdatedSince('2026-08-01T12:00:00+02:00'));
        self::assertNotNull(MediaController::normaliseUpdatedSince('2026-08-01T12:00:00Z'));
        self::assertNotNull(MediaController::normaliseUpdatedSince('2026-08-01T12:00:00.123Z'));
    }
}
