<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - Adds `original_label` and `original_country` snapshot columns so
 *   stripEnrichment can restore user-entered values that were overwritten
 *   by an enrichment source.
 * - Adds indexes that the audit identified as missing.
 */
class Version0002Date20260505000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('crate_media_items')) {
            $media = $schema->getTable('crate_media_items');

            if (!$media->hasColumn('original_label')) {
                $media->addColumn('original_label', Types::STRING, ['notnull' => false, 'length' => 500]);
            }
            if (!$media->hasColumn('original_country')) {
                $media->addColumn('original_country', Types::STRING, ['notnull' => false, 'length' => 100]);
            }

            if (!$media->hasIndex('crate_media_user_updated')) {
                $media->addIndex(['user_id', 'updated_at'], 'crate_media_user_updated');
            }
            if (!$media->hasIndex('crate_media_user_barcode')) {
                $media->addIndex(['user_id', 'barcode'], 'crate_media_user_barcode');
            }
            if (!$media->hasIndex('crate_media_user_discogs')) {
                $media->addIndex(['user_id', 'discogs_id'], 'crate_media_user_discogs');
            }
        }

        if ($schema->hasTable('crate_shares')) {
            $shares = $schema->getTable('crate_shares');
            if (!$shares->hasIndex('crate_share_shareable')) {
                $shares->addIndex(['shareable_type', 'shareable_id'], 'crate_share_shareable');
            }
        }

        return $schema;
    }
}
