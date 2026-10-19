<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the table that spaces calls to a rate-limited provider.
 *
 * PriceCharting allows one call per second per API token. The earliest time
 * each token may call again has to be shared by every PHP worker and survive
 * between requests, which the database does on every install — a memory
 * cache is optional and may be per-process. One row per token, keyed by a
 * hash of it; see ApiSlotStore.
 */
class Version0010Date20261019000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('crate_api_slots')) {
            return $schema;
        }

        $table = $schema->createTable('crate_api_slots');
        $table->addColumn('slot_key', Types::STRING, ['notnull' => true, 'length' => 64]);
        // Microseconds since the epoch: a whole number every database and
        // cache stores exactly.
        $table->addColumn('next_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['slot_key']);

        return $schema;
    }
}
