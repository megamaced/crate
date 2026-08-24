<?php

declare(strict_types=1);

namespace OCA\Crate;

/**
 * Hosts the enrichment sources serve cover images from.
 *
 * Three call sites need this list and must not drift apart: the artwork
 * proxy's SSRF allowlist, the validation of a caller-supplied artwork path,
 * and the img-src additions on Crate's own page.
 */
final class CrateImageHosts
{
    /** @var list<string> */
    public const ALL = [
        // Discogs
        'i.discogs.com', 'img.discogs.com', 'st.discogs.com',
        // TMDB
        'image.tmdb.org',
        // RAWG
        'media.rawg.io',
        // ComicVine
        'comicvine.gamespot.com', 'static.comicvine.com',
        // Open Library
        'covers.openlibrary.org',
    ];

    public static function isAllowed(string $host): bool
    {
        return in_array($host, self::ALL, true);
    }

    /**
     * True when $path is a shape the artwork endpoint can serve: the literal
     * 'local' for a user-uploaded file, or an https URL on an allowlisted
     * host. Anything else would be stored and handed back to clients as an
     * image source without ever passing through the artwork proxy.
     */
    public static function isValidArtworkPath(string $path): bool
    {
        if ($path === 'local') {
            return true;
        }
        if (parse_url($path, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        return self::isAllowed((string)(parse_url($path, PHP_URL_HOST) ?? ''));
    }

    private function __construct()
    {
    }
}
