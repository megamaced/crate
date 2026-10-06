<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use OCA\Crate\CrateCategories;
use OCA\Crate\Service\DiscogsFormat;
use OCA\Crate\Service\ImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DiscogsFormat turns a Discogs format into the canonical music format Crate
 * stores. The export strings below are the shapes a Discogs collection CSV
 * export writes; the token lists are what the Discogs API returns.
 */
class DiscogsFormatTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function exportStringProvider(): array
    {
        return [
            'LP'                         => ['LP, Album', 'Vinyl'],
            'quantity prefix'            => ['2xLP, Album, RE, 180', 'Vinyl'],
            '7 inch'                     => ['7", Single, Styrene', '7" Single'],
            '12 inch set'                => ['2x12"', '12" Single'],
            '10 inch'                    => ['10"', '10"'],
            'picture disc'               => ['LP, Album, Pic', 'Picture Disc'],
            'flexi'                      => ['Flexi, 7", Single', 'Flexi-disc'],
            'shellac'                    => ['Shellac, 10", 78 RPM', 'Shellac'],
            'lathe cut'                  => ['Lathe, 7"', 'Lathe Cut'],
            'cassette'                   => ['Cass, Album, Dol', 'Cassette'],
            'microcassette'              => ['M/cass, Album', 'Microcassette'],
            '8-track'                    => ['8-Trk, Album', '8-Track'],
            'reel'                       => ['Reel, Album', 'Reel-to-Reel'],
            'CD set'                     => ['2xCD, Album', 'CD'],
            'HDCD description'           => ['CD, Album, HDCD', 'HDCD'],
            'HDCD in place of CD'        => ['HDCD, Album', 'HDCD'],
            'CD-R'                       => ['CDr, Album', 'CD-R'],
            'SACD, cut off at 50 chars'  => ['SACD, Hybrid, Multichannel, Album, RM, DAD + SACD,', 'SACD'],
            'minidisc'                   => ['MD, Album', 'MiniDisc'],
            'DVD-Audio in place of DVD'  => ['DVD-A, Album, Multichannel', 'DVD-Audio'],
            'DVD-Audio description'      => ['DVD, DVD-A, Album', 'DVD-Audio'],
            'Blu-ray Audio'              => ['Blu-ray, Blu-ray-A, Album', 'Blu-ray Audio'],
            'box set contents'           => ['Box, Comp + 5xCD, Album', 'CD'],
            'all media descriptions'     => ['LP, Album, RE, RM + LP, Album + Dlx, 180', 'Vinyl'],
            'first music format of set'  => ['DVD-V, NTSC + CD, Album', 'CD'],
            'cut-off set'                => ['Box, Comp, Ltd + 4xLP, Album + Box,', 'Vinyl'],
            'DVD-Video'                  => ['DVD-V, Comp, Multichannel, NTSC', null],
            'plain DVD'                  => ['DVD, NTSC, Reg', null],
            'plain Blu-ray'              => ['Blu-ray, Album', null],
            'VHS'                        => ['VHS, PAL', null],
            'laserdisc is not a record'  => ['Laserdisc, 12", Album, NTSC', null],
            'CDV'                        => ['CDV, Single', null],
            'acetate'                    => ['Acetate, 12", 33 ⅓ RPM', null],
            'cylinder'                   => ['Cyl, Single', null],
            'hybrid'                     => ['Hybrid, DualDisc, Album, Multichannel, NTSC + DVD-', null],
            'digital file'               => ['File, MP3, Album', null],
            'floppy'                     => ['Floppy Disk, Album', null],
            'box set alone'              => ['Box, Comp', null],
        ];
    }

    #[DataProvider('exportStringProvider')]
    public function testExportString(string $export, ?string $expected): void
    {
        self::assertSame($expected, DiscogsFormat::fromExportString($export));
    }

    public function testSearchResultTokens(): void
    {
        self::assertSame('Vinyl', DiscogsFormat::fromTokens(['Box Set', 'Compilation', 'Vinyl', 'LP']));
        self::assertSame('7" Single', DiscogsFormat::fromTokens(['Vinyl', '7"', '45 RPM', 'Single']));
        self::assertSame('Picture Disc', DiscogsFormat::fromTokens(['Vinyl', 'LP', 'Picture Disc']));
        self::assertSame('HDCD', DiscogsFormat::fromTokens(['CD', 'Album', 'HDCD']));
        self::assertSame('CD', DiscogsFormat::fromTokens(['DVD', 'DVD-Video', 'CD', 'Album']));
        self::assertSame('DVD-Audio', DiscogsFormat::fromTokens(['DVD', 'DVD-Audio']));
        self::assertNull(DiscogsFormat::fromTokens(['Laserdisc', '12"', 'NTSC']));
        self::assertSame('Vinyl', DiscogsFormat::fromTokens(['Vinyl', 'LP', 'Laserdisc', '12"', 'NTSC']));
        self::assertSame('CD', DiscogsFormat::fromTokens(['CD', 'Album', 'VHS', 'HDCD']));
        self::assertNull(DiscogsFormat::fromTokens(['File', 'FLAC']));
    }

    public function testReleaseSegments(): void
    {
        self::assertSame('CD-R', DiscogsFormat::fromSegments([['Box Set', 'Compilation'], ['CDr', 'Album']]));
        self::assertSame('8-Track', DiscogsFormat::fromSegments([['8-Track Cartridge', 'Album']]));
        self::assertSame('Blu-ray Audio', DiscogsFormat::fromSegments([['Blu-ray', 'Blu-ray Audio']]));
        self::assertNull(DiscogsFormat::fromSegments([['Blu-ray', 'Album']]));
    }

    public function testEveryMappedFormatIsACanonicalMusicFormat(): void
    {
        /** @var array<string, string|array<string, string>> $formats */
        $formats = (new \ReflectionClass(DiscogsFormat::class))->getConstant('FORMATS');
        /** @var array<string, string> $music */
        $music = (new \ReflectionClass(ImportService::class))->getConstant('MUSIC_FORMATS');

        $mapped = [];
        array_walk_recursive($formats, static function (string $canonical) use (&$mapped): void {
            $mapped[$canonical] = true;
        });
        foreach (array_keys($mapped) as $canonical) {
            self::assertContains($canonical, $music, "\"{$canonical}\" is not an importable music format");
        }
    }

    public function testMusicRowsAcceptOnlyMusicFormats(): void
    {
        self::assertSame('Vinyl', ImportService::resolveFormat('vinyl', CrateCategories::MUSIC));
        self::assertSame('Vinyl', ImportService::resolveFormat('LP', CrateCategories::MUSIC));
        self::assertSame('DVD', ImportService::resolveFormat('DVD', CrateCategories::FILM));
        self::assertNull(ImportService::resolveFormat('LP, Album', CrateCategories::FILM));
        self::assertNull(ImportService::resolveFormat('DVD, Album', CrateCategories::MUSIC));
        self::assertNull(ImportService::resolveFormat('DVD', CrateCategories::MUSIC));
        self::assertNull(ImportService::resolveFormat('LaserDisc', CrateCategories::MUSIC));
        self::assertNull(ImportService::resolveFormat('CDV', CrateCategories::MUSIC));
        self::assertSame('LaserDisc', ImportService::resolveFormat('LaserDisc', CrateCategories::FILM));
    }
}
