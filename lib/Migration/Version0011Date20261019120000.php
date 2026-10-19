<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the generation counter behind each of an item's image slots.
 *
 * An upload is written under a name of its own generation and the row records
 * which generation it now points at, so the file a slot is using is a fact
 * about the row rather than about the file — see ItemImageSlot. Existing
 * uploads start at 0, which keeps their plain, pre-generation names.
 */
class Version0011Date20261019120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_media_items')) {
            return $schema;
        }

        $media = $schema->getTable('crate_media_items');
        foreach (['artwork_rev', 'photo1_rev', 'photo2_rev'] as $column) {
            if (!$media->hasColumn($column)) {
                $media->addColumn($column, Types::INTEGER, ['notnull' => true, 'default' => 0]);
            }
        }

        return $schema;
    }
}
