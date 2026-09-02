<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Dto\MediaItemData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The web and Android forms bound what they send; a direct API client does
 * not. Everything below used to reach the driver as-is, where the outcome
 * depended on the engine — an unhandled exception surfacing as a 500 on
 * PostgreSQL and strict MySQL, a silent truncation on non-strict MySQL, an
 * unbounded value on SQLite. The DTO is where that becomes one 400 everywhere.
 */
class MediaItemDataValidationTest extends TestCase
{
    private static function data(
        string $title = 'Kind of Blue',
        string $artist = 'Miles Davis',
        string $format = 'LP',
        ?int $year = null,
        ?string $barcode = null,
    ): MediaItemData {
        return new MediaItemData($title, $artist, $format, $year, $barcode);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function blankCoreFields(): array
    {
        return [
            ['', 'Miles Davis', 'LP'],
            ['   ', 'Miles Davis', 'LP'],
            ['Kind of Blue', '', 'LP'],
            ['Kind of Blue', "\t\n ", 'LP'],
            ['Kind of Blue', 'Miles Davis', ''],
            ['Kind of Blue', 'Miles Davis', '  '],
        ];
    }

    #[DataProvider('blankCoreFields')]
    public function testBlankCoreFieldsAreRejected(string $title, string $artist, string $format): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::data($title, $artist, $format);
    }

    public function testCoreFieldsAreStoredTrimmed(): void
    {
        $data = self::data('  Kind of Blue  ', "\tMiles Davis\n", ' LP ');
        self::assertSame('Kind of Blue', $data->title);
        self::assertSame('Miles Davis', $data->artist);
        self::assertSame('LP', $data->format);
    }

    /** @return list<array{0: string, 1: int}> */
    public static function boundedFields(): array
    {
        return [
            ['title', 500],
            ['artist', 500],
            ['format', 50],
            ['barcode', 50],
        ];
    }

    #[DataProvider('boundedFields')]
    public function testValueAtTheColumnWidthIsAcceptedAndOneOverIsNot(string $field, int $max): void
    {
        $atLimit = str_repeat('a', $max);
        self::assertSame($atLimit, self::data(...[$field => $atLimit])->$field);

        $this->expectException(\InvalidArgumentException::class);
        self::data(...[$field => str_repeat('a', $max + 1)]);
    }

    public function testLengthIsMeasuredInCharactersNotBytes(): void
    {
        // 500 multi-byte characters is 1500 bytes but still fits a
        // VARCHAR(500), so measuring bytes would reject a legitimate title.
        $title = str_repeat('あ', 500);
        self::assertSame($title, self::data(title: $title)->title);
    }

    /** @return list<array{0: int}> */
    public static function rejectedYears(): array
    {
        return [[0], [-1], [999], [99999], [2147483647]];
    }

    #[DataProvider('rejectedYears')]
    public function testYearOutsideTheColumnsUsefulRangeIsRejected(int $year): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::data(year: $year);
    }

    public function testPlausibleYearsAreAccepted(): void
    {
        self::assertSame(1000, self::data(year: 1000)->year);
        self::assertSame(1959, self::data(year: 1959)->year);
        self::assertSame((int) date('Y') + 1, self::data(year: (int) date('Y') + 1)->year);
        self::assertNull(self::data()->year);
    }
}
