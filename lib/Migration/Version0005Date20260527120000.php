<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 14: two user-supplied photo slots per item, separate from the
 * existing artwork (which holds the media's cover art). Used for shots of
 * the disc, receipts, sleevenotes etc.
 *
 * Each column mirrors `artwork_path` semantics: 'local' when a file is
 * stored in appdata, NULL when the slot is empty.
 */
class Version0005Date20260527120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crate_media_items')) {
            return $schema;
        }

        $media = $schema->getTable('crate_media_items');

        if (!$media->hasColumn('photo1_path')) {
            $media->addColumn('photo1_path', Types::STRING, ['notnull' => false, 'length' => 1000]);
        }
        if (!$media->hasColumn('photo2_path')) {
            $media->addColumn('photo2_path', Types::STRING, ['notnull' => false, 'length' => 1000]);
        }

        return $schema;
    }
}
