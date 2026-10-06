<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

/**
 * Maps a Discogs format onto Crate's canonical music format.
 *
 * Discogs describes each format of a release as a name plus descriptions. The
 * API hands them over as separate strings (name "Vinyl", descriptions "LP",
 * "Picture Disc"); the collection CSV export abbreviates them into one string,
 * with an "Nx" quantity and " + " between the formats of a set
 * ("2xLP, Album, Pic + CD").
 *
 * A format resolves by its name, with the descriptions only refining it, so a
 * carrier Crate doesn't take — video, digital, or anything not listed in
 * FORMATS — resolves to null rather than to whatever its descriptions name
 * ("Laserdisc, 12"" is not a 12" record). A set resolves to its first format
 * that maps.
 */
final class DiscogsFormat
{
    /** Vinyl: the variant named by a description, checked in order. */
    private const VINYL = [
        'pic'          => 'Picture Disc',
        'picture disc' => 'Picture Disc',
        '7"'           => '7" Single',
        '10"'          => '10"',
        '12"'          => '12" Single',
        ''             => 'Vinyl',
    ];

    /**
     * Discogs format name => canonical format, or => [description =>
     * canonical] checked in order, where '' is the result when no description
     * matches and its absence means the format isn't taken without one.
     *
     * The export puts a description in place of some names (LP or 7" for
     * Vinyl, HDCD for CD, DVD-A for DVD), so those descriptions are listed as
     * names too; LEADING_DESCRIPTIONS marks them.
     */
    private const FORMATS = [
        // Records
        'vinyl'             => self::VINYL,
        'lp'                => self::VINYL,
        '7"'                => self::VINYL,
        '10"'               => self::VINYL,
        '12"'               => self::VINYL,
        'shellac'           => 'Shellac',
        'flexi'             => 'Flexi-disc',
        'flexi-disc'        => 'Flexi-disc',
        'lathe'             => 'Lathe Cut',
        'lathe cut'         => 'Lathe Cut',
        // Tapes
        'cass'              => 'Cassette',
        'cassette'          => 'Cassette',
        'm/cass'            => 'Microcassette',
        'microcassette'     => 'Microcassette',
        '8-trk'             => '8-Track',
        '8-track cartridge' => '8-Track',
        '4-trk'             => '4-Track Cartridge',
        '4-track cartridge' => '4-Track Cartridge',
        'reel'              => 'Reel-to-Reel',
        'reel-to-reel'      => 'Reel-to-Reel',
        'dat'               => 'DAT',
        'dcc'               => 'DCC',
        // Discs
        'cd'                => ['hdcd' => 'HDCD', 'shm-cd' => 'SHM-CD', '' => 'CD'],
        'hdcd'              => 'HDCD',
        'shm-cd'            => 'SHM-CD',
        'cdr'               => 'CD-R',
        'sacd'              => 'SACD',
        'md'                => 'MiniDisc',
        'minidisc'          => 'MiniDisc',
        // DVD and Blu-ray only as audio discs (not DVD-V or plain Blu-ray)
        'dvd'               => ['dvd-a' => 'DVD-Audio', 'dvd-audio' => 'DVD-Audio'],
        'dvd-a'             => 'DVD-Audio',
        'blu-ray'           => ['blu-ray-a' => 'Blu-ray Audio', 'blu-ray audio' => 'Blu-ray Audio'],
        'blu-ray-a'         => 'Blu-ray Audio',
    ];

    /** FORMATS keys that the API sends as descriptions rather than names. */
    private const LEADING_DESCRIPTIONS = ['lp', '7"', '10"', '12"', 'hdcd', 'shm-cd', 'dvd-a', 'blu-ray-a'];

    /**
     * API format names that map to nothing: the containers that only group
     * other formats, and every carrier Crate doesn't take. Listed so a search
     * result's flat token list splits at them, and their descriptions can't
     * narrow the format before them ("Vinyl, LP, Laserdisc, 12"").
     */
    private const UNMAPPED_NAMES = [
        'box set', 'all media',
        'file', 'floppy disk', 'zip disk', 'memory stick', 'hitclips',
        'acetate', 'mighty tiny', 'sopic', 'pathé disc', 'edison disc', 'cylinder',
        'dc-international', 'elcaset', 'playtape', 'rca tape cartridge', 'nt cassette',
        'pocket rocker', 'revere magnetic stereo tape ca', 'tefifon', 'sabamobil', 'wire recording',
        'hybrid', 'cdv', 'laserdisc', 'dvdr', 'hd dvd', 'hd dvd-r', 'blu-ray-r', 'ultra hd blu-ray',
        'vhs', 'super vhs', 'betamax', 'betacam', 'betacam sp', 'video 2000', 'video8', 'minidv',
        'u-matic', 'cartrivision', 'selectavision', 'ted', 'vhd', 'mvd', 'umd', 'film reel',
    ];

    /**
     * Resolve a collection-export format string, e.g. "2xLP, Album, RE + CD".
     */
    public static function fromExportString(string $format): ?string
    {
        return self::fromSegments(array_map(
            static fn(string $segment): array => explode(',', $segment),
            explode('+', $format),
        ));
    }

    /**
     * Resolve formats given one per segment as [name, ...descriptions], the
     * way a Discogs release lists them.
     *
     * @param list<list<string>> $segments
     */
    public static function fromSegments(array $segments): ?string
    {
        foreach ($segments as $segment) {
            $canonical = self::resolveSegment(array_map(self::normalise(...), $segment));
            if ($canonical !== null) {
                return $canonical;
            }
        }
        return null;
    }

    /**
     * Resolve a search result's flat list of every format's name and
     * descriptions, e.g. ["Box Set", "Compilation", "Vinyl", "LP"].
     *
     * @param string[] $tokens
     */
    public static function fromTokens(array $tokens): ?string
    {
        $segments = [];
        foreach ($tokens as $token) {
            $key = self::normalise($token);
            $isName = in_array($key, self::UNMAPPED_NAMES, true)
                || (isset(self::FORMATS[$key]) && !in_array($key, self::LEADING_DESCRIPTIONS, true));
            if ($segments === [] || $isName) {
                $segments[] = [];
            }
            $segments[count($segments) - 1][] = $key;
        }
        return self::fromSegments($segments);
    }

    /**
     * @param list<string> $segment normalised [name, ...descriptions]
     */
    private static function resolveSegment(array $segment): ?string
    {
        $format = self::FORMATS[$segment[0] ?? ''] ?? null;
        if (!is_array($format)) {
            return $format;
        }
        foreach ($format as $description => $canonical) {
            if ($description === '' || in_array($description, $segment, true)) {
                return $canonical;
            }
        }
        return null;
    }

    private static function normalise(string $token): string
    {
        $token = mb_strtolower(trim($token), 'UTF-8');
        // Discogs sometimes folds shellac into a compound name.
        if (str_contains($token, 'shellac')) {
            return 'shellac';
        }
        // Drop the quantity of a multi-disc set (2xLP, 3x7").
        return (string)preg_replace('/^\d+\s*x\s*/', '', $token);
    }
}
