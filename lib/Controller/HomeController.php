<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\Db\MediaItemMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class HomeController extends OCSController
{
    use UsesAuthenticatedUser;

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly MediaItemMapper $mapper,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/home
     * Returns per-category home feed: item-of-the-day, recent items, most-valuable.
     * Used by the Android app; the web frontend computes this client-side.
     */
    #[NoAdminRequired]
    public function home(): DataResponse
    {
        $owned = array_values(array_filter(
            $this->mapper->findAll(),
            fn($i) => $i->getStatus() === 'owned',
        ));

        // Group by category
        $byCategory = [];
        foreach ($owned as $item) {
            $cat = $item->getCategory() ?? 'music';
            $byCategory[$cat][] = $item;
        }

        // Deterministic daily seed
        $seed = (int)(new \DateTime())->format('Ymd');

        $categories = [];
        foreach ($byCategory as $cat => $items) {
            $idx = $seed % count($items);
            $categories[$cat] = [
                'count'       => count($items),
                'itemOfDay'   => $items[$idx],
                'recentItems' => array_slice($items, 0, 6),
            ];
        }

        $valuable = array_values(array_filter($owned, fn($i) => $i->getMarketValue() !== null));
        usort($valuable, fn($a, $b) => ($b->getMarketValue() ?? 0) <=> ($a->getMarketValue() ?? 0));

        return new DataResponse([
            'categories'    => $categories,
            'recentlyAdded' => array_slice($owned, 0, 12),
            'mostValuable'  => array_slice($valuable, 0, 6),
        ]);
    }
}
