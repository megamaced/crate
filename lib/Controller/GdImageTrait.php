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
 * Both fail open: if GD is missing or the image fails to decode, we hand the
 * original bytes back rather than 500 the request.
 *
 * Consuming controllers must expose a `$logger` property; both current ones
 * take it as a promoted constructor parameter.
 *
 * @property LoggerInterface $logger
 */
trait GdImageTrait
{
    /**
     * Pixel budget for a GD decode. imagecreatefromstring() allocates roughly
     * four bytes per pixel of the *decoded* image, which the byte size of the
     * compressed input says nothing about — a 10 MB flat-colour PNG can be
     * 20000x20000. Running out of memory is a fatal E_ERROR, so no handler can
     * turn it into a failed request: the only defence is refusing to decode.
     */
    private const MAX_DECODE_PIXELS = 50_000_000;

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
     * Re-encode image bytes to strip EXIF/IPTC/XMP metadata. Only JPEG and PNG
     * are re-encoded — those are the formats that commonly carry GPS and
     * camera-identifying metadata. WebP/GIF are returned unchanged because
     * a GD round-trip would silently destroy animation, and these formats
     * rarely carry the kind of metadata we want to drop.
     *
     * On any failure (GD missing, decode error, encode error) the original
     * bytes are returned — we'd rather store an item with intact metadata
     * than 500 on the upload.
     */
    private function stripImageMetadata(string $data, string $mime): string
    {
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $data;
        }
        if (!function_exists('imagecreatefromstring')) {
            return $data;
        }

        $src = $this->gdSafeDecode($data);
        if ($src === false) {
            return $data;
        }

        try {
            ob_start();
            if ($mime === 'image/png') {
                imagealphablending($src, false);
                imagesavealpha($src, true);
                imagepng($src);
            } else {
                imagejpeg($src, null, 90);
            }
            $out = ob_get_clean();
        } finally {
            imagedestroy($src);
        }

        return ($out !== false && $out !== '') ? $out : $data;
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
            && ($width * $height) <= self::MAX_DECODE_PIXELS;
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
