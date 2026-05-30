<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
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
 * original bytes back rather than 500 the request. The audit (Phase 16, 2026-05-30)
 * called out the EXIF gap as the highest-severity finding — see the wiki Code
 * Audits page for context.
 */
trait GdImageTrait
{
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
                        return $response;
                    }
                }
            }
        }
        $response = new DataDisplayResponse($data, Http::STATUS_OK, ['Content-Type' => $mime]);
        $response->cacheFor($cacheSeconds);
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
            $logger = property_exists($this, 'logger') ? $this->logger : null;
            if ($logger instanceof LoggerInterface) {
                $logger->warning('Image decode failed: {msg}', [
                    'msg' => $e->getMessage(),
                    'app' => 'crate',
                ]);
            }
            $src = false;
        }
        return $src;
    }
}
