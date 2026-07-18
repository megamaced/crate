<?php

declare(strict_types=1);

namespace OCA\Crate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Consolidated initial schema for the Crate app (replaces the 0001–0009
 * incremental migrations from the 2026-04-12 → 2026-04-20 development
 * window). The app was still in pre-release testing with a single user,
 * so the earlier migrations are flattened into one authoritative CREATE.
 *
 * Tables:
 *   crate_media_items    — catalogue items across all five categories
 *   crate_playlists      — user-defined playlists
 *   crate_playlist_items — join with position, FK-cascaded to both sides
 *   crate_shares         — polymorphic album/playlist sharing between users
 */
class Version0001Date20260421000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // ── Media items ───────────────────────────────────────────────────────
        if (!$schema->hasTable('crate_media_items')) {
            $media = $schema->createTable('crate_media_items');

            $media->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $media->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);

            // Core item fields
            $media->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 500]);
            $media->addColumn('artist', Types::STRING, ['notnull' => true, 'length' => 500]);
            $media->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 50]);
            $media->addColumn('year', Types::INTEGER, ['notnull' => false]);
            $media->addColumn('barcode', Types::STRING, ['notnull' => false, 'length' => 50]);
            $media->addColumn('notes', Types::TEXT, ['notnull' => false]);
            $media->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length'  => 10,
                'default' => 'owned',
            ]);
            $media->addColumn('category', Types::STRING, [
                'notnull' => true,
                'length'  => 16,
                'default' => 'music',
            ]);

            // Enrichment ID (named `discogs_id` for historical reasons; holds
            // TMDB / RAWG / ComicVine / Open Library work keys too).
            $media->addColumn('discogs_id', Types::STRING, ['notnull' => false, 'length' => 50]);
            $media->addColumn('artwork_path', Types::STRING, ['notnull' => false, 'length' => 1000]);

            // Enrichment detail fields
            $media->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 500]);
            $media->addColumn('country', Types::STRING, ['notnull' => false, 'length' => 100]);
            $media->addColumn('genres', Types::STRING, ['notnull' => false, 'length' => 500]);
            $media->addColumn('tracklist', Types::TEXT, ['notnull' => false]);
            $media->addColumn('pressing_notes', Types::TEXT, ['notnull' => false]);
            $media->addColumn('discogs_artist_id', Types::STRING, ['notnull' => false, 'length' => 50]);
            $media->addColumn('artist_bio', Types::TEXT, ['notnull' => false]);
            $media->addColumn('artist_members', Types::TEXT, ['notnull' => false]);

            // Pre-enrichment snapshot — used by "Remove Discogs data" to restore originals
            $media->addColumn('original_title', Types::STRING, ['notnull' => false, 'length' => 500]);
            $media->addColumn('original_artist', Types::STRING, ['notnull' => false, 'length' => 500]);
            $media->addColumn('original_year', Types::INTEGER, ['notnull' => false]);
            $media->addColumn('original_artwork_path', Types::STRING, ['notnull' => false, 'length' => 1000]);

            // Market value (Discogs for music, PriceCharting for game/comic)
            $media->addColumn('market_value', Types::FLOAT, ['notnull' => false]);
            $media->addColumn('market_value_loose', Types::FLOAT, ['notnull' => false]);
            $media->addColumn('market_value_new', Types::FLOAT, ['notnull' => false]);
            $media->addColumn('market_value_currency', Types::STRING, ['notnull' => false, 'length' => 3]);
            $media->addColumn('market_value_fetched_at', Types::DATETIME, ['notnull' => false]);

            $media->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $media->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

            $media->setPrimaryKey(['id']);
            $media->addIndex(['user_id'], 'crate_media_user_id');
            $media->addIndex(['user_id', 'format'], 'crate_media_user_format');
            $media->addIndex(['user_id', 'status'], 'crate_media_user_status');
            $media->addIndex(['user_id', 'category'], 'crate_media_user_category');
        }

        // ── Playlists ─────────────────────────────────────────────────────────
        if (!$schema->hasTable('crate_playlists')) {
            $playlists = $schema->createTable('crate_playlists');

            $playlists->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $playlists->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $playlists->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 500]);
            $playlists->addColumn('description', Types::TEXT, ['notnull' => false]);
            $playlists->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $playlists->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

            $playlists->setPrimaryKey(['id']);
            $playlists->addIndex(['user_id'], 'crate_playlist_user_id');
        }

        // ── Playlist items (FK-cascaded to both parents) ─────────────────────
        if (!$schema->hasTable('crate_playlist_items')) {
            $items = $schema->createTable('crate_playlist_items');

            $items->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $items->addColumn('playlist_id', Types::INTEGER, ['notnull' => true]);
            $items->addColumn('media_item_id', Types::INTEGER, ['notnull' => true]);
            $items->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $items->addColumn('added_at', Types::DATETIME, ['notnull' => true]);

            $items->setPrimaryKey(['id']);
            $items->addIndex(['playlist_id'], 'crate_pli_playlist_id');
            $items->addIndex(['media_item_id'], 'crate_pli_media_item_id');
            $items->addUniqueIndex(['playlist_id', 'media_item_id'], 'crate_pli_unique');

            $items->addForeignKeyConstraint(
                $schema->getTable('crate_playlists'),
                ['playlist_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'crate_pli_fk_playlist',
            );
            $items->addForeignKeyConstraint(
                $schema->getTable('crate_media_items'),
                ['media_item_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'crate_pli_fk_media_item',
            );
        }

        // ── Shares (polymorphic: album | playlist) ────────────────────────────
        if (!$schema->hasTable('crate_shares')) {
            $shares = $schema->createTable('crate_shares');

            $shares->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $shares->addColumn('owner_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $shares->addColumn('shared_with_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $shares->addColumn('shareable_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $shares->addColumn('shareable_id', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $shares->addColumn('created_at', Types::DATETIME, ['notnull' => true]);

            $shares->setPrimaryKey(['id']);
            $shares->addIndex(['owner_user_id'], 'crate_share_owner');
            $shares->addIndex(['shared_with_user_id'], 'crate_share_recipient');
            $shares->addUniqueIndex(
                ['owner_user_id', 'shared_with_user_id', 'shareable_type', 'shareable_id'],
                'crate_share_unique',
            );
        }

        return $schema;
    }
}
