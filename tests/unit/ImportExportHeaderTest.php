<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\ImportController;
use OCA\Crate\Service\ExportService;
use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the export→import round trip together. Two separate lists have to
 * agree with ImportService::ALIASES for an export to be re-importable:
 * ImportController::VALID_MAPPING_FIELDS (which rejects the whole commit on an
 * unknown field) and the headers ExportService emits per category.
 */
class ImportExportHeaderTest extends TestCase
{
    public function testEveryAliasTargetIsAnAcceptedMappingField(): void
    {
        $targets = array_unique(array_values(ImportService::ALIASES));

        foreach ($targets as $target) {
            self::assertContains(
                $target,
                ImportController::VALID_MAPPING_FIELDS,
                "auto-detection can produce \"{$target}\", so /import/commit must accept it",
            );
        }
    }

    public function testPriceHeadersAreDetectedAndAccepted(): void
    {
        // Header row of an includePrice=1 export.
        $headers = ['Category', 'Artist', 'Album', 'Format', 'Purchase Price', 'Purchase Currency'];
        $mapping = ImportService::detectMapping($headers);

        self::assertSame('purchasePrice', $mapping[4]);
        self::assertSame('purchasePriceCurrency', $mapping[5]);
        foreach ($mapping as $field) {
            self::assertContains($field, ImportController::VALID_MAPPING_FIELDS);
        }
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function categoryProvider(): array
    {
        return [
            'music'    => ['music'],
            'film'     => ['film'],
            'book'     => ['book'],
            'game'     => ['game'],
            'comic'    => ['comic'],
            'all'      => [null],
        ];
    }

    #[DataProvider('categoryProvider')]
    public function testExportHeadersReImportForEveryCategory(?string $category): void
    {
        $headers = $this->exportHeaders($category);
        $mapping = ImportService::detectMapping($headers);
        $fields  = array_values(array_filter($mapping, static fn($f) => $f !== null));

        $label = $category ?? 'all';
        foreach (['artist', 'title', 'format'] as $required) {
            self::assertContains(
                $required,
                $fields,
                "the {$label} export must expose a {$required} column the importer recognises",
            );
        }
        // Everything the detector does resolve must survive the commit check.
        foreach ($fields as $field) {
            self::assertContains($field, ImportController::VALID_MAPPING_FIELDS);
        }
    }

    #[DataProvider('categoryProvider')]
    public function testEnrichedExportHeadersReImportForEveryCategory(?string $category): void
    {
        $headers = $this->exportHeaders($category, true, true, true);
        $mapping = ImportService::detectMapping($headers);
        $fields  = array_values(array_filter($mapping, static fn($f) => $f !== null));

        foreach (['artist', 'title', 'format'] as $required) {
            self::assertContains($required, $fields);
        }
        foreach ($fields as $field) {
            self::assertContains($field, ImportController::VALID_MAPPING_FIELDS);
        }
        // Each header maps to at most one canonical field.
        self::assertSame(
            count($fields),
            count(array_unique($fields)),
            'two headers of the same export resolved to the same field: ' . implode(', ', $fields),
        );
    }

    /**
     * ExportService::buildHeaders is private and the surrounding service only
     * needs its mapper for the row data, so drive the header builder directly.
     *
     * @return string[]
     */
    private function exportHeaders(
        ?string $category,
        bool $includeEnriched = false,
        bool $includeMarket = false,
        bool $includePrice = true,
    ): array {
        $reflection = new \ReflectionClass(ExportService::class);
        $service    = $reflection->newInstanceWithoutConstructor();
        $method     = $reflection->getMethod('buildHeaders');

        /** @var string[] $headers */
        $headers = $method->invoke($service, $includeEnriched, $includeMarket, $category, $includePrice);
        return $headers;
    }
}
