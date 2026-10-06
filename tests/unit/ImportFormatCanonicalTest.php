<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\TestCase;

/**
 * ImportService::VALID_FORMATS decides both which formats an import accepts and
 * the spelling it stores. Format strings are compared exactly downstream (chip
 * grouping, colour gradients, format-aware enrichment matching), so the stored
 * spelling has to be the one the UI knows — the list in
 * src/utils/categoryFormats.js.
 */
class ImportFormatCanonicalTest extends TestCase
{
    /** @return array<string, string> */
    private function validFormats(): array
    {
        /** @var array<string, string> $formats */
        $formats = (new \ReflectionClass(ImportService::class))->getConstant('VALID_FORMATS');
        self::assertNotEmpty($formats);
        return $formats;
    }

    public function testEveryKeyIsTheLowercaseOfItsCanonicalSpelling(): void
    {
        foreach ($this->validFormats() as $key => $canonical) {
            self::assertSame(
                $key,
                strtolower($canonical),
                "\"{$canonical}\" is keyed as \"{$key}\", so it could never be matched",
            );
        }
    }

    public function testCanonicalSpellingsMatchTheFrontendFormatList(): void
    {
        $js = file_get_contents(__DIR__ . '/../../src/utils/categoryFormats.js');
        self::assertNotFalse($js);

        preg_match_all('/formats:\s*\[(.*?)\]/s', $js, $blocks);
        $jsFormats = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([^']*)'/", $block, $names);
            foreach ($names[1] as $name) {
                $jsFormats[$name] = true;
            }
        }
        self::assertGreaterThan(50, count($jsFormats), 'failed to read FORMAT_GROUPS');

        $valid = $this->validFormats();
        foreach (array_keys($jsFormats) as $name) {
            $key = strtolower($name);
            self::assertArrayHasKey($key, $valid, "format \"{$name}\" cannot be imported");
            self::assertSame($name, $valid[$key], 'import stores a non-canonical spelling');
        }
    }

    public function testMusicFormatsAreExactlyTheFrontendMusicGroups(): void
    {
        $js = file_get_contents(__DIR__ . '/../../src/utils/categoryFormats.js');
        self::assertNotFalse($js);
        self::assertSame(1, preg_match('/FORMAT_GROUPS = \{\s*music:\s*\[(.*?)\bfilm:/s', $js, $music));
        preg_match_all('/formats:\s*\[(.*?)\]/s', $music[1], $blocks);
        $jsMusic = [];
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([^']*)'/", $block, $names);
            array_push($jsMusic, ...$names[1]);
        }

        /** @var array<string, string> $formats */
        $formats = (new \ReflectionClass(ImportService::class))->getConstant('MUSIC_FORMATS');
        $php = array_values($formats);
        sort($php);
        sort($jsMusic);
        self::assertSame($jsMusic, $php, 'a music import must accept exactly the music formats the UI offers');
    }
}
