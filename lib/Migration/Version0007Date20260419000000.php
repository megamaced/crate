<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a category column to crate_media_items (music | film | book | game).
 * Backfills existing rows to 'music'.
 */
class Version0007Date20260419000000 extends SimpleMigrationStep
{
    public function __construct(
        private readonly IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $table  = $schema->getTable('crate_media_items');

        if (!$table->hasColumn('category')) {
            $table->addColumn('category', Types::STRING, [
                'notnull' => false,
                'length'  => 16,
            ]);
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update('crate_media_items')
            ->set('category', $qb->createNamedParameter('music'))
            ->where($qb->expr()->isNull('category'));
        $qb->executeStatement();
    }
}
