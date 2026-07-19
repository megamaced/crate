<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Controller\MediaController;
use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two purchase-price validation entry points introduced in
 * Phase 13 and tightened during the Phase 16 audit:
 *
 *   - MediaController::normalisePurchasePrice — JSON API path
 *   - ImportService::parsePurchasePriceCell / parsePurchaseCurrencyCell —
 *     CSV/XLSX import path
 *
 * Both share the same range bounds (0..1_000_000) and the same currency
 * allowlist (MarketValueService::SUPPORTED_CURRENCIES); the goal of these
 * tests is to lock that contract down so the two paths can't drift.
 */
class PurchasePriceTest extends TestCase
{
    // ── MediaController::normalisePurchasePrice ────────────────────────────────

    public function testNormaliseNullPairClears(): void
    {
        $r = MediaController::normalisePurchasePrice(null, null);
        self::assertSame(['price' => null, 'currency' => null], $r);
    }

    public function testNormaliseNullPriceClearsEvenWithStrayCurrency(): void
    {
        // The frontend can send a stale currency after the user clears the
        // price input; we treat that as "clear both" rather than 400.
        $r = MediaController::normalisePurchasePrice(null, 'GBP');
        self::assertSame(['price' => null, 'currency' => null], $r);
    }

    public function testNormaliseRejectsNegativePrice(): void
    {
        $r = MediaController::normalisePurchasePrice(-0.01, 'GBP');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('out of range', $r['error']);
    }

    public function testNormaliseRejectsAbsurdlyLargePrice(): void
    {
        $r = MediaController::normalisePurchasePrice(1_000_000.01, 'GBP');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('out of range', $r['error']);
    }

    public function testNormaliseAcceptsBoundaryValues(): void
    {
        // 0 is a legitimate "I got it free" entry (locked in by the
        // AddEditModal predicate fix from the Phase 16 audit).
        self::assertSame(
            ['price' => 0.0, 'currency' => 'GBP'],
            MediaController::normalisePurchasePrice(0.0, 'GBP'),
        );
        self::assertSame(
            ['price' => 1_000_000.0, 'currency' => 'GBP'],
            MediaController::normalisePurchasePrice(1_000_000.0, 'GBP'),
        );
    }

    public function testNormaliseRequiresCurrencyWhenPriceIsSet(): void
    {
        $r = MediaController::normalisePurchasePrice(24.99, null);
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('required', $r['error']);
    }

    public function testNormaliseRejectsEmptyStringCurrency(): void
    {
        $r = MediaController::normalisePurchasePrice(24.99, '   ');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('required', $r['error']);
    }

    public function testNormaliseRejectsUnknownCurrencyCode(): void
    {
        $r = MediaController::normalisePurchasePrice(24.99, 'XYZ');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('Unsupported', $r['error']);
    }

    public function testNormaliseUppercasesAndTrimsCurrency(): void
    {
        $r = MediaController::normalisePurchasePrice(24.99, '  gbp ');
        self::assertSame(['price' => 24.99, 'currency' => 'GBP'], $r);
    }

    // ── ImportService::parsePurchasePriceCell ──────────────────────────────────

    public function testParsePriceCellNullIsBlank(): void
    {
        self::assertSame(['price' => null], ImportService::parsePurchasePriceCell(null));
    }

    public function testParsePriceCellEmptyStringIsBlank(): void
    {
        self::assertSame(['price' => null], ImportService::parsePurchasePriceCell(''));
    }

    public function testParsePriceCellStripsCurrencySymbol(): void
    {
        // Spreadsheets often paste "£24.99" or "$1,299.00" verbatim.
        self::assertSame(['price' => 24.99], ImportService::parsePurchasePriceCell('£24.99'));
        self::assertSame(['price' => 1299.0], ImportService::parsePurchasePriceCell('$1,299.00'));
    }

    public function testParsePriceCellAcceptsPlainNumber(): void
    {
        self::assertSame(['price' => 24.99], ImportService::parsePurchasePriceCell('24.99'));
        self::assertSame(['price' => 0.0], ImportService::parsePurchasePriceCell('0'));
    }

    public function testParsePriceCellAcceptsCommaDecimal(): void
    {
        // European decimal-comma cells must not be read as 100x too large:
        // "24,99" is 24.99, not 2499. (Regression: the old parser stripped
        // every comma unconditionally.)
        self::assertSame(['price' => 24.99], ImportService::parsePurchasePriceCell('24,99'));
        self::assertSame(['price' => 1234.56], ImportService::parsePurchasePriceCell('1.234,56'));
        self::assertSame(['price' => 1234.56], ImportService::parsePurchasePriceCell('1,234.56'));
        self::assertSame(['price' => 1299.0], ImportService::parsePurchasePriceCell('£1.299,00'));
        self::assertSame(['price' => 12.5], ImportService::parsePurchasePriceCell('12,5'));
    }

    public function testParsePriceCellTreatsCommaGroupsAsThousands(): void
    {
        // A comma grouping three digits ("1,234") is a thousands separator.
        self::assertSame(['price' => 1234.0], ImportService::parsePurchasePriceCell('1,234'));
        self::assertSame(['price' => 12000.0], ImportService::parsePurchasePriceCell('12,000'));
    }

    public function testParsePriceCellBlankAfterTrim(): void
    {
        self::assertSame(['price' => null], ImportService::parsePurchasePriceCell('   '));
    }

    public function testParsePriceCellRejectsGibberish(): void
    {
        $r = ImportService::parsePurchasePriceCell('not a price');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('unparseable', $r['error']);
    }

    public function testParsePriceCellRejectsOutOfRange(): void
    {
        $r = ImportService::parsePurchasePriceCell('99999999');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('out of range', $r['error']);
    }

    public function testParsePriceCellRejectsNegative(): void
    {
        $r = ImportService::parsePurchasePriceCell('-5');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('out of range', $r['error']);
    }

    // ── ImportService::parsePurchaseCurrencyCell ───────────────────────────────

    public function testParseCurrencyCellAcceptsSupportedCode(): void
    {
        self::assertSame(['currency' => 'GBP'], ImportService::parsePurchaseCurrencyCell('gbp'));
        self::assertSame(['currency' => 'USD'], ImportService::parsePurchaseCurrencyCell('  USD  '));
    }

    public function testParseCurrencyCellRejectsEmpty(): void
    {
        $r = ImportService::parsePurchaseCurrencyCell('');
        self::assertArrayHasKey('error', $r);
        self::assertSame('purchase price requires a currency', $r['error']);
    }

    public function testParseCurrencyCellRejectsUnknownCode(): void
    {
        $r = ImportService::parsePurchaseCurrencyCell('XYZ');
        self::assertArrayHasKey('error', $r);
        self::assertStringContainsString('unsupported', $r['error']);
    }
}
