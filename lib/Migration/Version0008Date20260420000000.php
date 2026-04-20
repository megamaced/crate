<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds market_value_loose and market_value_new columns for PriceCharting data.
 * market_value stores CIB price for games/comics; music keeps using it as the
 * single Discogs lowest price.
 */
class Version0008Date20260420000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table  = $schema->getTable('crate_media_items');

        if (!$table->hasColumn('market_value_loose')) {
            $table->addColumn('market_value_loose', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('market_value_new')) {
            $table->addColumn('market_value_new', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
