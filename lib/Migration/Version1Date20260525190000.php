<?php

/**
 * Tier-2 maps (NC Maps / Location) integration migration.
 *
 * Ensures the `openregister_map_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - favorite_id (integer, not null) — `maps_favorites` row id
 *  - name (string 255, not null) — cached POI name
 *  - category (string 255, nullable) — cached POI category
 *  - lat (double, not null) — cached latitude
 *  - lng (double, not null) — cached longitude
 *  - comment (text, nullable) — cached POI comment
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 photos/talk/polls/email/forms/
 * deck/flow link tables; the wrapping `MapLinkService` replaces the
 * `[or:{uuid}]` favorite-name marker convention used by the wave-1
 * `MapsProvider` with a proper persistence layer so links survive POI
 * renames.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tier-2 map-links table — create-or-extend.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260525190000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('openregister_map_links') === false) {
            $this->createMapLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_map_links') === true
            && $this->extendMapLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_map_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createMapLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_map_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('favorite_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('lat', Types::FLOAT, ['notnull' => true]);
        $table->addColumn('lng', Types::FLOAT, ['notnull' => true]);
        $table->addColumn('comment', Types::TEXT, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'favorite_id'], 'idx_map_object_fav');
        $table->addIndex(['object_uuid'], 'idx_map_object');
        $table->addIndex(['register_id'], 'idx_map_register');
        $table->addIndex(['schema_id'], 'idx_map_schema');

        $output->info('Created openregister_map_links table (Tier-2 schema)');
    }//end createMapLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing map_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendMapLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_map_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_map_schema');
            $output->info('Added schema_id column to openregister_map_links');
            $changed = true;
        }

        if ($table->hasColumn('category') === false) {
            $table->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added category column to openregister_map_links');
            $changed = true;
        }

        if ($table->hasColumn('comment') === false) {
            $table->addColumn('comment', Types::TEXT, ['notnull' => false, 'default' => null]);
            $output->info('Added comment column to openregister_map_links');
            $changed = true;
        }

        return $changed;
    }//end extendMapLinksTable()
}//end class
