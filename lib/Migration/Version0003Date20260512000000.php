<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCA\Crate\CrateCategories;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * One-time cleanup: clear market_value* columns on rows whose category
 * has no market-value source (film, book). These rows acquired stray
 * Discogs prices because the refresh-all flow treated their shared
 * enrichment id (a TMDB / Open Library id stored in the discogs_id
 * column) as a Discogs release id.
 */
class Version0003Date20260512000000 extends SimpleMigrationStep
{
    public function __construct(private readonly IDBConnection $db)
    {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $nonMarket = array_values(array_diff(CrateCategories::ALL, CrateCategories::MARKET_CATEGORIES));
        if ($nonMarket === []) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('crate_media_items')
            ->set('market_value', $qb->createNamedParameter(null))
            ->set('market_value_loose', $qb->createNamedParameter(null))
            ->set('market_value_new', $qb->createNamedParameter(null))
            ->set('market_value_currency', $qb->createNamedParameter(null))
            ->set('market_value_fetched_at', $qb->createNamedParameter(null))
            ->where($qb->expr()->in(
                'category',
                $qb->createNamedParameter($nonMarket, IQueryBuilder::PARAM_STR_ARRAY),
            ));
        $affected = $qb->executeStatement();

        $output->info("Crate: cleared market_value* on {$affected} non-market rows (film/book).");
    }
}
