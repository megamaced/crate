<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds Discogs marketplace pricing columns to crate_media_items.
 */
class Version0006Date20260417000001 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table = $schema->getTable('crate_media_items');

        if (!$table->hasColumn('market_value')) {
            $table->addColumn('market_value', Types::FLOAT, [
                'notnull' => false,
            ]);
        }
        if (!$table->hasColumn('market_value_currency')) {
            $table->addColumn('market_value_currency', Types::STRING, [
                'notnull' => false,
                'length'  => 3,
            ]);
        }
        if (!$table->hasColumn('market_value_fetched_at')) {
            $table->addColumn('market_value_fetched_at', Types::DATETIME, [
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
