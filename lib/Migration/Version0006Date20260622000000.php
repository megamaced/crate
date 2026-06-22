<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 18: whole-library and per-category sharing.
 *
 * Adds shareable_category to crate_shares so 'category' shares can record
 * which of the five categories was shared. The sentinel '-' is used for
 * the existing 'album' and 'playlist' shares and for whole-library shares
 * — non-empty only for category shares (CrateShare::CATEGORY_NONE). The
 * composite unique key is rebuilt to include the new column so
 * (owner, recipient, 'category', 'music') and
 * (owner, recipient, 'category', 'film') are distinct rows.
 *
 * Default is the literal '-' (Nextcloud's migration validator rejects
 * NOT NULL columns whose default is the empty string or null).
 */
class Version0006Date20260622000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_shares')) {
            return $schema;
        }

        $shares = $schema->getTable('crate_shares');

        if (!$shares->hasColumn('shareable_category')) {
            $shares->addColumn('shareable_category', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => '-',
            ]);
        }

        if ($shares->hasIndex('crate_share_unique')) {
            $shares->dropIndex('crate_share_unique');
        }
        if (!$shares->hasIndex('crate_share_unique')) {
            $shares->addUniqueIndex(
                ['owner_user_id', 'shared_with_user_id', 'shareable_type', 'shareable_id', 'shareable_category'],
                'crate_share_unique',
            );
        }

        return $schema;
    }
}
