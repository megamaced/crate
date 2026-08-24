<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the index behind the collection's own list query.
 *
 * Every listing — the grid, the paginated REST endpoint, the search provider —
 * reads `WHERE user_id = ? ORDER BY created_at DESC, id DESC`. The table
 * already carries several `user_id` composites, but none of them covers
 * `created_at`, so that sort was a filesort over the user's whole collection.
 */
class Version0009Date20260822000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_media_items')) {
            return $schema;
        }

        $media = $schema->getTable('crate_media_items');
        if (!$media->hasIndex('crate_media_user_created')) {
            $media->addIndex(['user_id', 'created_at'], 'crate_media_user_created');
        }

        return $schema;
    }
}
