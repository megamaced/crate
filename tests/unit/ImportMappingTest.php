<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the header→field mapping used by CSV/XLSX import. Every header
 * ExportService emits must resolve to a field, or an export→import round-trip
 * silently drops that column — the "Barcode / ISBN" header from the
 * all-categories export is the case most easily missed, since "barcode" and
 * "isbn" on their own do not match it.
 */
class ImportMappingTest extends TestCase
{
    public function testDetectMappingResolvesAllCategoriesExportHeaders(): void
    {
        // Header row as emitted by the "all" export scope.
        $headers = ['Category', 'Artist', 'Title', 'Format', 'Barcode / ISBN', 'Label', 'Notes'];
        $mapping = ImportService::detectMapping($headers);

        self::assertSame('category', $mapping[0]);
        self::assertSame('artist', $mapping[1]);
        self::assertSame('title', $mapping[2]);
        self::assertSame('format', $mapping[3]);
        self::assertSame('barcode', $mapping[4], 'the "Barcode / ISBN" column must map to barcode');
        self::assertSame('label', $mapping[5]);
        self::assertSame('notes', $mapping[6]);
    }

    public function testDetectMappingResolvesSingleCategoryBarcodeAndIsbn(): void
    {
        self::assertSame('barcode', ImportService::detectMapping(['Barcode'])[0]);
        self::assertSame('barcode', ImportService::detectMapping(['ISBN'])[0]);
        self::assertSame('barcode', ImportService::detectMapping(['barcode/isbn'])[0]);
    }

    public function testApplyMappingRoundTripsBarcode(): void
    {
        $headers = ['Artist', 'Title', 'Format', 'Barcode / ISBN'];
        $mapping = ImportService::detectMapping($headers);
        $rows = [['Miles Davis', 'Kind of Blue', 'Vinyl', '5099746711917']];

        $mapped = ImportService::applyMapping($rows, $mapping);

        self::assertSame('5099746711917', $mapped[0]['barcode']);
        self::assertSame('Miles Davis', $mapped[0]['artist']);
    }

    public function testDetectMappingLeavesUnknownHeadersUnmapped(): void
    {
        $mapping = ImportService::detectMapping(['Artist', 'Totally Unknown Column']);
        self::assertSame('artist', $mapping[0]);
        self::assertNull($mapping[1]);
    }
}
