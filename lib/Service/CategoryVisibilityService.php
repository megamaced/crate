<?php

declare(strict_types=1);

namespace OCA\Crate\Service;

use OCA\Crate\CrateCategories;
use OCP\IConfig;

/**
 * Single source of truth for the per-user `hidden_categories` setting.
 *
 * Read by the Home feed, the `/me` profile payload and the unified-search
 * provider. Playlists deliberately do not consult this: an item already in a
 * playlist stays in it whatever its category's visibility, so a playlist's
 * contents don't silently change when a category is hidden.
 */
class CategoryVisibilityService
{
    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    /**
     * Categories the user has hidden, as a clean list of known category keys.
     * Tolerates stale entries written by older clients.
     *
     * @return list<string>
     */
    public function hidden(string $userId): array
    {
        $raw = $this->config->getUserValue($userId, 'crate', 'hidden_categories', '[]');
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            $decoded,
            static fn($c) => is_string($c) && CrateCategories::isCategory($c),
        )));
    }

    /**
     * Categories the user can currently see. The setter guarantees at least
     * one remains, so this is never empty for a valid stored value.
     *
     * @return list<string>
     */
    public function visible(string $userId): array
    {
        $hidden = $this->hidden($userId);
        return array_values(array_filter(
            CrateCategories::ALL,
            static fn(string $c) => !in_array($c, $hidden, true),
        ));
    }
}
