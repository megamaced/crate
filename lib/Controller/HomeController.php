<?php

declare(strict_types=1);

namespace OCA\Crate\Controller;

use OCA\Crate\CrateCategories;
use OCA\Crate\Db\MediaItemMapper;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
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
        private readonly IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /api/v1/home
     * Returns per-category home feed: item-of-the-day, recent items, most-valuable.
     * Hidden categories are filtered out across all three sections so the
     * Home view honours the user's hidden_categories setting.
     */
    #[NoAdminRequired]
    public function home(): DataResponse
    {
        $hidden = $this->loadHiddenCategories();

        $owned = array_values(array_filter(
            $this->mapper->findAll($this->userId()),
            fn($i) => $i->getStatus() === 'owned' && !in_array($i->getCategory(), $hidden, true),
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

    /**
     * @return string[]
     */
    private function loadHiddenCategories(): array
    {
        $raw = $this->config->getUserValue($this->userId(), 'crate', 'hidden_categories', '[]');
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(
            $decoded,
            static fn($c) => is_string($c) && in_array($c, CrateCategories::ALL, true),
        ));
    }
}
