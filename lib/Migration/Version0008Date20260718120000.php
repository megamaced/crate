<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Read/write sharing: add a `permission` column to crate_shares.
 *
 * Existing shares are read-only, so the column defaults to 'read'. A share
 * granted 'readwrite' lets the recipient add and edit items within the shared
 * scope (but not delete them, and not manage the share itself). See
 * CrateShare::PERMISSION_*.
 *
 * Default is the literal 'read' (Nextcloud's migration validator rejects
 * NOT NULL columns whose default is the empty string or null — same reason
 * shareable_category uses '-'). The unique index is unchanged: permission is
 * an attribute of an existing (owner, recipient, type, id, category) share,
 * not part of its identity.
 */
class Version0008Date20260718120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_shares')) {
            return $schema;
        }

        $shares = $schema->getTable('crate_shares');

        if (!$shares->hasColumn('permission')) {
            $shares->addColumn('permission', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'read',
            ]);
        }

        return $schema;
    }
}
