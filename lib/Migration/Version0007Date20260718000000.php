<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix: whole-library and per-category shares failed to insert on Postgres
 * with a NOT NULL violation on crate_shares.shareable_id.
 *
 * Library and category shares carry no album/playlist id, so ShareService
 * calls setShareableId(0). Because the entity's shareableId already defaults
 * to 0, Nextcloud's Entity setter treats it as unchanged and omits the column
 * from the INSERT. The column was NOT NULL with no default, so Postgres wrote
 * NULL and rejected the row (MySQL non-strict silently coerced to 0).
 *
 * Giving the column a server-side default of 0 makes the omitted column
 * resolve to 0 on every engine, matching the sentinel default already used
 * for shareable_category in Version0006.
 */
class Version0007Date20260718000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_shares')) {
            return $schema;
        }

        $shares = $schema->getTable('crate_shares');

        if ($shares->hasColumn('shareable_id')) {
            $column = $shares->getColumn('shareable_id');
            $column->setDefault(0);
            $column->setNotnull(true);
        }

        return $schema;
    }
}
