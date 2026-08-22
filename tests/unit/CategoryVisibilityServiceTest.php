<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\CategoryVisibilityService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The hidden-categories setting is written by clients as free-form JSON, so
 * the reader has to survive stale category keys, malformed JSON and wrong
 * types without hiding everything (or nothing) by accident.
 */
class CategoryVisibilityServiceTest extends TestCase
{
    private function serviceReturning(string $stored): CategoryVisibilityService
    {
        $config = $this->createMock(IConfig::class);
        $config->expects(self::atLeastOnce())
            ->method('getUserValue')
            ->with('alice', 'crate', 'hidden_categories', '[]')
            ->willReturn($stored);

        return new CategoryVisibilityService($config);
    }

    public function testUnsetSettingHidesNothing(): void
    {
        self::assertSame([], $this->serviceReturning('[]')->hidden('alice'));
    }

    public function testHiddenReturnsStoredCategories(): void
    {
        self::assertSame(
            ['game', 'comic'],
            $this->serviceReturning('["game","comic"]')->hidden('alice'),
        );
    }

    public function testUnknownCategoriesFromOlderClientsAreDropped(): void
    {
        self::assertSame(
            ['film'],
            $this->serviceReturning('["film","movie","",null,42]')->hidden('alice'),
        );
    }

    public function testDuplicatesAreCollapsedAndKeysReindexed(): void
    {
        self::assertSame(
            ['book'],
            $this->serviceReturning('["book","book"]')->hidden('alice'),
        );
    }

    public function testMalformedJsonHidesNothing(): void
    {
        self::assertSame([], $this->serviceReturning('not json')->hidden('alice'));
    }

    public function testNonArrayJsonHidesNothing(): void
    {
        self::assertSame([], $this->serviceReturning('"music"')->hidden('alice'));
    }

    public function testVisibleIsTheComplementInCanonicalOrder(): void
    {
        self::assertSame(
            ['music', 'book', 'comic'],
            $this->serviceReturning('["game","film"]')->visible('alice'),
        );
    }

    public function testVisibleIsEverythingWhenNothingHidden(): void
    {
        self::assertSame(
            ['music', 'film', 'book', 'game', 'comic'],
            $this->serviceReturning('[]')->visible('alice'),
        );
    }
}
