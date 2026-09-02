<?php

declare(strict_types=1);

namespace OCA\Crate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What actually reaches disk after an upload, and what the decode budget
 * allows. Both are properties of the *saved bytes* and of the worker's
 * memory_limit — not of whether a decode happened to succeed.
 */
class ImageSanitisationTest extends TestCase
{
    private function host(): GdImageTraitHost
    {
        return new GdImageTraitHost(new NullLogger());
    }

    private function requireGd(): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            self::markTestSkipped('GD is not available');
        }
    }

    // ── Animated WebP detection ──────────────────────────────────────────────

    /**
     * Build a minimal RIFF/WEBP container carrying a VP8X chunk with the given
     * flag byte. Bit 1 of that byte is ANIM.
     */
    private function vp8xWebp(int $flags): string
    {
        $chunk = 'VP8X' . pack('V', 10) . chr($flags) . str_repeat("\0", 9);
        return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
    }

    public function testAnimatedWebpIsRecognisedByItsVp8xFlag(): void
    {
        // 0x02 = ANIM. 0x08 = EXIF present but still a single frame.
        self::assertTrue($this->host()->animated($this->vp8xWebp(0x02)));
        self::assertTrue($this->host()->animated($this->vp8xWebp(0x1E)));
        self::assertFalse($this->host()->animated($this->vp8xWebp(0x08)));
        self::assertFalse($this->host()->animated($this->vp8xWebp(0x00)));
    }

    public function testNonVp8xBytesAreNotTreatedAsAnimated(): void
    {
        $host = $this->host();
        self::assertFalse($host->animated(''));
        self::assertFalse($host->animated('RIFF'));
        self::assertFalse($host->animated('RIFF' . pack('V', 20) . 'WEBPVP8 ' . str_repeat("\0", 12)));
        self::assertFalse($host->animated(str_repeat("\xFF", 64)));
    }

    public function testAnimatedWebpIsRefusedRatherThanFlattenedOrPassedThrough(): void
    {
        // Refusing is the point: flattening destroys the animation, and
        // passing it through stores the EXIF/XMP a VP8X chunk can carry.
        self::assertNull($this->host()->strip($this->vp8xWebp(0x02), 'image/webp'));
    }

    // ── Fail-closed ──────────────────────────────────────────────────────────

    public function testUndecodableBytesAreReportedRatherThanReturnedIntact(): void
    {
        self::assertNull($this->host()->strip('not an image at all', 'image/jpeg'));
    }

    public function testAnUnexpectedTypeIsRefused(): void
    {
        self::assertNull($this->host()->strip('<svg/>', 'image/svg+xml'));
    }

    public function testGifIsTheOneDeliberatePassThrough(): void
    {
        // A GD round-trip destroys animation and remaps the palette, and the
        // format carries comment blocks rather than EXIF/GPS.
        $gif = 'GIF89a' . str_repeat("\0", 20);
        self::assertSame($gif, $this->host()->strip($gif, 'image/gif'));
    }

    // ── Saved bytes ──────────────────────────────────────────────────────────

    public function testExifIsGoneFromTheSavedJpegBytes(): void
    {
        $this->requireGd();

        $im = imagecreatetruecolor(24, 24);
        ob_start();
        imagejpeg($im, null, 90);
        $plain = (string) ob_get_clean();
        imagedestroy($im);

        // Splice an APP1 EXIF segment in after SOI, the way a phone camera
        // writes one. GD cannot produce metadata, so the fixture has to.
        $payload = "Exif\0\0" . 'GPSLatitude 51.5074 GPSLongitude -0.1278';
        $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        $withExif = substr($plain, 0, 2) . $app1 . substr($plain, 2);

        self::assertStringContainsString('GPSLatitude', $withExif, 'fixture should carry EXIF');

        $stripped = $this->host()->strip($withExif, 'image/jpeg');
        self::assertNotNull($stripped);
        self::assertStringNotContainsString('GPSLatitude', $stripped);
        self::assertStringNotContainsString("\xFF\xE1", $stripped);
        // Still a usable image, not just shorter bytes.
        self::assertNotFalse(@getimagesizefromstring($stripped));
    }

    public function testStaticWebpIsReEncodedRatherThanReturnedUntouched(): void
    {
        $this->requireGd();
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD has no WebP support');
        }

        $im = imagecreatetruecolor(24, 24);
        ob_start();
        imagewebp($im, null, 90);
        $webp = (string) ob_get_clean();
        imagedestroy($im);

        $stripped = $this->host()->strip($webp, 'image/webp');
        self::assertNotNull($stripped);
        self::assertSame('RIFF', substr($stripped, 0, 4));
        self::assertSame('WEBP', substr($stripped, 8, 4));
        self::assertNotFalse(@getimagesizefromstring($stripped));
    }

    // ── Decode budget ────────────────────────────────────────────────────────

    public function testMemoryLimitShorthandIsRead(): void
    {
        $host     = $this->host();
        $original = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '256M');
            self::assertSame(256 * 1024 * 1024, $host->limitBytes());
            ini_set('memory_limit', '1G');
            self::assertSame(1024 * 1024 * 1024, $host->limitBytes());
            // A bare value is bytes. (It has to stay above the process's
            // current usage; PHP refuses to set a limit below that.)
            ini_set('memory_limit', '134217728');
            self::assertSame(134217728, $host->limitBytes());
            ini_set('memory_limit', '-1');
            self::assertNull($host->limitBytes(), 'unlimited has no byte value');
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    public function testBudgetTracksTheWorkersMemoryLimit(): void
    {
        $host     = $this->host();
        $original = ini_get('memory_limit');
        try {
            // A generous pool is still capped by the static ceiling: a limit
            // big enough to permit a 200 MB decode does not make one wise.
            ini_set('memory_limit', '4G');
            $generous = $host->budget();

            // A modest pool has to allow less than a generous one — that is
            // the whole point of deriving the number rather than fixing it.
            ini_set('memory_limit', '96M');
            $modest = $host->budget();

            self::assertGreaterThan($modest, $generous);
            self::assertLessThanOrEqual(24_000_000, $generous);
            self::assertGreaterThanOrEqual(1_000_000, $modest);
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    public function testAnImageOverTheBudgetIsRefusedBeforeAnyDecode(): void
    {
        // A 20000x2500 PNG header: 50 MP, well over the ceiling, and the file
        // that carries it compresses to a fraction of the 10 MB upload cap.
        $ihdr = 'IHDR' . pack('N', 20000) . pack('N', 2500) . "\x08\x06\x00\x00\x00";
        $png  = "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));

        self::assertFalse($this->host()->withinBudget($png));
    }
}
