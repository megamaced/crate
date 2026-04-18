<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Service\MediaService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\Crate\Controller\UsesAuthenticatedUser;

/**
 * GET /api/v1/home
 * Returns all data needed to render the home feed in a single request.
 * Designed for the Android app to avoid multiple round-trips.
 */
class HomeController extends OCSController
{
    use UsesAuthenticatedUser;

    private const ROW_COUNT     = 6;
    private const FORMAT_ORDER  = ['Vinyl', 'CD', 'Cassette', 'SACD', 'MiniDisc'];

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaService $mediaService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function home(): DataResponse
    {
        $items = $this->mediaService->findAll($this->userId());
        // Only owned items on the home feed
        $owned = array_values(array_filter($items, fn($i) => $i->getStatus() === 'owned'));

        if (empty($owned)) {
            return new DataResponse([
                'albumOfDay'   => null,
                'recentItems'  => [],
                'formatRows'   => [],
                'mostValuable' => [],
            ]);
        }

        $seed = $this->dateSeed();

        return new DataResponse([
            'albumOfDay'   => $owned[$seed % count($owned)],
            'recentItems'  => array_slice($owned, 0, self::ROW_COUNT),
            'formatRows'   => $this->buildFormatRows($owned, $seed),
            'mostValuable' => $this->buildMostValuable($owned),
        ]);
    }

    /** @param array<\OCA\Crate\Db\MediaItem> $items */
    private function buildFormatRows(array $items, int $seed): array
    {
        $allFormats = array_unique(array_filter(array_map(fn($i) => $i->getFormat(), $items)));
        // Preferred order first, then any remaining formats
        $ordered = array_merge(
            array_filter(self::FORMAT_ORDER, fn($f) => in_array($f, $allFormats, true)),
            array_values(array_filter($allFormats, fn($f) => !in_array($f, self::FORMAT_ORDER, true))),
        );

        $rows = [];
        foreach ($ordered as $fmt) {
            $pool = array_values(array_filter($items, fn($i) => $i->getFormat() === $fmt));
            if (empty($pool)) {
                continue;
            }
            $shuffled = $this->seededShuffle($pool, $seed + $this->strSeed($fmt));
            $rows[] = [
                'format' => $fmt,
                'label'  => $this->pluralLabel($fmt),
                'items'  => array_slice($shuffled, 0, self::ROW_COUNT),
            ];
        }
        return $rows;
    }

    /** @param array<\OCA\Crate\Db\MediaItem> $items */
    private function buildMostValuable(array $items): array
    {
        $withValue = array_filter($items, fn($i) => $i->getMarketValue() !== null);
        usort($withValue, fn($a, $b) => ($b->getMarketValue() ?? 0) <=> ($a->getMarketValue() ?? 0));
        return array_slice(array_values($withValue), 0, self::ROW_COUNT);
    }

    /**
     * Date-based seed — same algorithm as HomeView.vue so album-of-day matches
     * between the web app and the Android app on the same day.
     */
    private function dateSeed(): int
    {
        $s = date('D M d Y'); // matches JS `new Date().toDateString()` format e.g. "Thu Apr 17 2026"
        $h = 0;
        for ($i = 0; $i < strlen($s); $i++) {
            $h = $this->imul32(31, $h) + ord($s[$i]);
            $h &= 0xFFFFFFFF; // keep 32-bit
        }
        return abs($this->signed32($h));
    }

    /** @param array<mixed> $arr */
    private function seededShuffle(array $arr, int $seed): array
    {
        $a = $arr;
        $s = $seed;
        for ($i = count($a) - 1; $i > 0; $i--) {
            $s = ($this->imul32($s, 1664525) + 1013904223) & 0xFFFFFFFF;
            $j = abs($this->signed32($s)) % ($i + 1);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    }

    /** Simple string → int hash to make format seeds differ. */
    private function strSeed(string $s): int
    {
        $h = 0;
        for ($i = 0; $i < strlen($s); $i++) {
            $h += ord($s[$i]);
        }
        return $h;
    }

    /**
     * 32-bit signed multiply (mirrors JS Math.imul).
     *
     * On 64-bit PHP a naive ($a32 * $b32) can exceed PHP_INT_MAX and
     * silently convert to float, losing precision.  We split into 16-bit
     * halves and combine, exactly like the standard Math.imul polyfill.
     */
    private function imul32(int $a, int $b): int
    {
        $aHi = ($a >> 16) & 0xFFFF;
        $aLo = $a & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF;
        $bLo = $b & 0xFFFF;
        // Only the lower 32 bits matter; cross-products shifted left 16
        // are masked anyway, so overflow beyond 32 bits is discarded.
        return (($aHi * $bLo + $aLo * $bHi) << 16) + ($aLo * $bLo) & 0xFFFFFFFF;
    }

    /** Interpret a 32-bit unsigned int as signed. */
    private function signed32(int $n): int
    {
        $n = $n & 0xFFFFFFFF;
        if ($n >= 0x80000000) {
            return $n - 0x100000000;
        }
        return $n;
    }

    private function pluralLabel(string $fmt): string
    {
        if ($fmt === 'Vinyl') {
            return 'Vinyl';
        }
        if (str_ends_with($fmt, 's')) {
            return $fmt;
        }
        return $fmt . 's';
    }
}
