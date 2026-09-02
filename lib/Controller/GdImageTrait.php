<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * Shared GD plumbing for the image-bearing controllers (ArtworkController,
 * PhotoController). Two operations live here:
 *
 *  - {@see thumbResponse()} — decode → resize → JPEG-encode → DataDisplayResponse
 *  - {@see stripImageMetadata()} — decode → re-encode without metadata, so EXIF
 *    (GPS, camera serial, timestamps) doesn't survive an upload.
 *
 * The two differ in what a failure means. A thumbnail is a convenience, so it
 * falls back to the original bytes rather than 500 the request. Sanitisation is
 * not: it reports failure to its caller (null) instead of quietly storing an
 * unsanitised file, and the caller decides between refusing the write and
 * logging a deliberate pass-through.
 *
 * Consuming controllers must expose a `$logger` property; both current ones
 * take it as a promoted constructor parameter.
 *
 * @property LoggerInterface $logger
 */
trait GdImageTrait
{
    /**
     * Hard ceiling on the pixels a GD decode may produce, whatever the worker
     * can afford. imagecreatefromstring() allocates roughly four bytes per
     * pixel of the *decoded* image, which the byte size of the compressed
     * input says nothing about — a flat-colour PNG well under the 10 MB upload
     * cap decodes to 20000x20000. Running out of memory is a fatal E_ERROR, so
     * no handler can turn it into a failed request: the only defence is
     * refusing to decode.
     *
     * 24 MP is comfortably above any camera or scanner output a cover or a
     * receipt arrives as, and roughly 96 MB decoded before the compressed
     * string, GD's own overhead and the re-encode buffer.
     */
    private const MAX_DECODE_PIXELS = 24_000_000;

    /**
     * Floor for the derived budget below. Without it a worker that happens to
     * be holding a large request would start refusing ordinary uploads; 1 MP
     * is about 8 MB across both GD buffers, which any usable memory_limit has.
     */
    private const MIN_DECODE_PIXELS = 1_000_000;

    /** Bytes GD holds per pixel of a decoded truecolour image. */
    private const DECODE_BYTES_PER_PIXEL = 4;

    /**
     * GD buffers alive at once during the operations here: the decoded source,
     * plus the resize target (thumbResponse) or the re-encode output buffer
     * (stripImageMetadata).
     */
    private const DECODE_BUFFERS = 2;

    /** Per-side pixel cap, rejecting extreme aspect ratios under the budget. */
    private const MAX_DECODE_SIDE = 20000;

    /**
     * Resize image bytes to a 200×200-bounded thumbnail using GD. Returns the
     * thumbnail (or the original data on decode failure) wrapped in a cached
     * DataDisplayResponse. Caller passes its preferred cache TTL — artwork
     * keeps the old 86400s (one day), photos keep 3600s (one hour).
     */
    private function thumbResponse(string $data, string $mime, int $cacheSeconds): Response
    {
        $thumbSize = 200;
        if (function_exists('imagecreatefromstring') && function_exists('imagescale')) {
            $src = $this->gdSafeDecode($data);
            if ($src !== false) {
                $w     = imagesx($src);
                $h     = imagesy($src);
                $scale = min($thumbSize / $w, $thumbSize / $h, 1.0);
                $nw    = max(1, (int) round($w * $scale));
                $nh    = max(1, (int) round($h * $scale));
                $dst   = imagescale($src, $nw, $nh, IMG_BILINEAR_FIXED);
                imagedestroy($src);
                if ($dst !== false) {
                    ob_start();
                    imagejpeg($dst, null, 85);
                    $out = ob_get_clean();
                    imagedestroy($dst);
                    if ($out !== false && $out !== '') {
                        $response = new DataDisplayResponse(
                            $out,
                            Http::STATUS_OK,
                            ['Content-Type' => 'image/jpeg'],
                        );
                        $response->cacheFor($cacheSeconds);
                        return $this->hardenImageResponse($response);
                    }
                }
            }
        }
        $response = new DataDisplayResponse($data, Http::STATUS_OK, ['Content-Type' => $mime]);
        $response->cacheFor($cacheSeconds);
        return $this->hardenImageResponse($response);
    }

    /**
     * Apply the response hardening every binary image response in this app
     * needs. GIF and WebP bytes are served exactly as uploaded, so the browser
     * must be told not to re-interpret them as another type, and the strictest
     * available policy applies since an image response sources nothing.
     */
    private function hardenImageResponse(Response $response): Response
    {
        $response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /**
     * Re-encode image bytes to strip EXIF/IPTC/XMP metadata: GPS coordinates,
     * capture timestamps, camera and phone serial numbers. JPEG, PNG and
     * still WebP all go through a GD round-trip, which keeps only the pixels.
     *
     * WebP is included because a phone photo saved as one carries the same
     * EXIF and XMP a JPEG would, in a VP8X chunk — and a photo slot is exactly
     * where such a file lands. An *animated* WebP is refused rather than
     * flattened to its first frame: silently destroying the animation is worse
     * than declining the upload, and storing it unsanitised is the thing this
     * method exists to prevent.
     *
     * GIF is the one deliberate pass-through. A GD round-trip destroys
     * animation and remaps the palette, and the format carries comment blocks
     * rather than the EXIF/GPS this is guarding against.
     *
     * Returns null when sanitisation was required and could not be performed —
     * GD absent or lacking WebP, an animated WebP, a decode or encode failure.
     * Callers must not store those bytes without deciding to: an upload path
     * should refuse, and a fetch from an allowlisted enrichment CDN may log
     * and pass through.
     */
    private function stripImageMetadata(string $data, string $mime): ?string
    {
        if ($mime === 'image/gif') {
            return $data;
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->logImageSanitisationFailure('unsupported type ' . $mime);
            return null;
        }
        if (!function_exists('imagecreatefromstring')) {
            $this->logImageSanitisationFailure('GD is not available');
            return null;
        }
        if ($mime === 'image/webp') {
            if (!function_exists('imagewebp')) {
                $this->logImageSanitisationFailure('GD has no WebP support');
                return null;
            }
            if (self::isAnimatedWebp($data)) {
                $this->logImageSanitisationFailure('animated WebP cannot be re-encoded');
                return null;
            }
        }

        $src = $this->gdSafeDecode($data);
        if ($src === false) {
            // gdSafeDecode has already logged why.
            return null;
        }

        try {
            ob_start();
            if ($mime === 'image/jpeg') {
                imagejpeg($src, null, 90);
            } else {
                // PNG and WebP both carry alpha, which GD discards unless the
                // channel is saved explicitly.
                imagealphablending($src, false);
                imagesavealpha($src, true);
                if ($mime === 'image/png') {
                    imagepng($src);
                } else {
                    imagewebp($src, null, 90);
                }
            }
            $out = ob_get_clean();
        } finally {
            imagedestroy($src);
        }

        if ($out === false || $out === '') {
            $this->logImageSanitisationFailure('re-encode produced no output');
            return null;
        }
        return $out;
    }

    /**
     * True when WebP bytes are an animation. An animated file is the extended
     * format: a `VP8X` chunk immediately after the RIFF header, whose first
     * flag byte has bit 1 (ANIM) set. Anything shorter, or not a RIFF/WEBP
     * container at all, is not one.
     */
    private static function isAnimatedWebp(string $data): bool
    {
        if (strlen($data) < 21) {
            return false;
        }
        if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') {
            return false;
        }
        if (substr($data, 12, 4) !== 'VP8X') {
            return false;
        }
        return (ord($data[20]) & 0x02) !== 0;
    }

    private function logImageSanitisationFailure(string $reason): void
    {
        $this->logger->warning('Image metadata could not be stripped: {reason}', [
            'reason' => $reason,
            'app'    => 'crate',
        ]);
    }

    /**
     * Decode raw image bytes, converting GD's libpng/libjpeg warnings into a
     * clean boolean failure path. Returns a GdImage on success, or false on
     * any decode error.
     *
     * @return \GdImage|false
     */
    private function gdSafeDecode(string $data)
    {
        if (!$this->gdDimensionsWithinBudget($data)) {
            return false;
        }

        try {
            set_error_handler(static function (int $severity, string $message): bool {
                throw new \RuntimeException($message, $severity);
            });
            try {
                $src = imagecreatefromstring($data);
            } finally {
                restore_error_handler();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Image decode failed: {msg}', [
                'msg' => $e->getMessage(),
                'app' => 'crate',
            ]);
            $src = false;
        }
        return $src;
    }

    /**
     * True when the image header declares dimensions a GD decode can afford.
     * Called before every decode, and directly by the upload endpoints so they
     * can answer 413 before opening a transaction.
     */
    private function gdDimensionsWithinBudget(string $data): bool
    {
        $dims = $this->gdImageDimensions($data);
        if ($dims === null) {
            return false;
        }
        [$width, $height] = $dims;
        return $width <= self::MAX_DECODE_SIDE
            && $height <= self::MAX_DECODE_SIDE
            && ($width * $height) <= $this->decodePixelBudget();
    }

    /**
     * Pixels this worker can decode right now: what is left of `memory_limit`
     * after the request's current allocation, divided between the GD buffers a
     * decode-and-re-encode holds simultaneously, and clamped to the constants
     * above.
     *
     * A fixed budget is the wrong shape for this check — whether a decode is
     * survivable is a fact about the pool's `memory_limit`, not about the
     * image. The static ceiling stays as the upper bound because a limit
     * generous enough to permit a 200 MB decode does not make one a good idea.
     */
    private function decodePixelBudget(): int
    {
        $limit = self::memoryLimitBytes();
        if ($limit === null) {
            // memory_limit=-1, or an ini value that cannot be read. Nothing
            // bounds a decode but the OS, and there the failure is an OOM kill
            // that takes the whole worker rather than one request, so the
            // static ceiling is all there is.
            return self::MAX_DECODE_PIXELS;
        }
        $headroom   = $limit - memory_get_usage(true);
        $affordable = intdiv(max(0, $headroom), self::DECODE_BYTES_PER_PIXEL * self::DECODE_BUFFERS);
        return max(self::MIN_DECODE_PIXELS, min(self::MAX_DECODE_PIXELS, $affordable));
    }

    /**
     * `memory_limit` in bytes, or null when it is unlimited or unreadable.
     * The ini value carries PHP's K/M/G shorthand, which is binary.
     */
    private static function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }
        if (preg_match('/^(\d+)\s*([KMG])?$/i', $raw, $m) !== 1) {
            return null;
        }
        $bytes = (int) $m[1];
        return match (strtoupper($m[2] ?? '')) {
            'K'     => $bytes * 1024,
            'M'     => $bytes * 1024 * 1024,
            'G'     => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

    /**
     * Read the declared pixel dimensions out of raw image bytes without
     * decoding them, or null when the header cannot be read.
     *
     * @return array{0: int, 1: int}|null
     */
    private function gdImageDimensions(string $data): ?array
    {
        try {
            set_error_handler(static function (int $severity, string $message): bool {
                throw new \RuntimeException($message, $severity);
            });
            try {
                $info = getimagesizefromstring($data);
            } finally {
                restore_error_handler();
            }
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($info)) {
            return null;
        }
        $width  = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1) {
            return null;
        }
        return [$width, $height];
    }
}
