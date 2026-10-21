<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the item's star rating: a whole number of stars from 1 to 5, or null
 * while the item is unrated.
 */
class Version0012Date20261021000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_media_items')) {
            return $schema;
        }

        $media = $schema->getTable('crate_media_items');
        if (!$media->hasColumn('rating')) {
            $media->addColumn('rating', Types::SMALLINT, ['notnull' => false]);
        }

        return $schema;
    }
}
