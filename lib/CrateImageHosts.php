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

    /**
     * Domains an allowlisted host redirects a cover request to, matched
     * including their subdomains.
     *
     * covers.openlibrary.org holds only part of its catalogue itself; the rest
     * answers 302 into the Internet Archive — first archive.org, then whichever
     * datanode has the zip (ia600505.us.archive.org and siblings). Both the
     * browser and the artwork proxy re-check every hop of a redirect chain, so
     * a cover whose bytes live in the archive renders as an empty box unless
     * these are allowed as well.
     *
     * Redirect targets only: no enrichment result ever names one, which is why
     * they stay out of ALL and cannot be stored in `artwork_path`.
     *
     * @var list<string>
     */
    public const REDIRECT_DOMAINS = ['archive.org'];

    public static function isAllowed(string $host): bool
    {
        return in_array($host, self::ALL, true);
    }

    /**
     * True when $host may be followed part-way through an artwork fetch: an
     * allowlisted host, or one of the redirect domains above.
     */
    public static function isAllowedRedirectTarget(string $host): bool
    {
        if (self::isAllowed($host)) {
            return true;
        }
        foreach (self::REDIRECT_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * img-src sources for Crate's own page: every host above, plus each
     * redirect domain and a wildcard for its datanodes. The browser applies
     * img-src to each hop of a redirect, so a source list covering only the
     * first hop blocks the image the redirect leads to.
     *
     * @return list<string>
     */
    public static function imageSources(): array
    {
        $sources = array_map(static fn(string $host): string => 'https://' . $host, self::ALL);
        foreach (self::REDIRECT_DOMAINS as $domain) {
            $sources[] = 'https://' . $domain;
            $sources[] = 'https://*.' . $domain;
        }
        return $sources;
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
