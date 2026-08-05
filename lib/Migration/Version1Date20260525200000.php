<?php

/**
 * Tier-2 collectives (NC Collectives / Knowledge) integration migration.
 *
 * Ensures the `openregister_collective_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - page_id (integer, not null) — `collectives_pages` row id
 *  - collective_id (integer, not null) — owning collective id
 *  - collective_name (string 255, not null) — cached collective title
 *  - page_title (string 255, not null) — cached page title
 *  - slug (string 255, nullable) — cached page slug
 *  - emoji (string 16, nullable) — cached page emoji
 *  - last_modified (datetime, nullable) — cached page timestamp
 *  - url (string 512, nullable) — cached deep link to the page
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 talk/polls/email/forms/deck/
 * flow/photos link tables; the wrapping `CollectiveLinkService` replaces
 * the `[or:{uuid}]` slug marker convention used by the Tier-1
 * `CollectivesProvider` with a proper persistence layer so links survive
 * page renames + moves.
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
 * Tier-2 collective-links table — create-or-extend.
 */
class Version1Date20260525200000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_collective_links') === false) {
            $this->createCollectiveLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_collective_links') === true
            && $this->extendCollectiveLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_collective_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createCollectiveLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_collective_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('page_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('collective_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('collective_name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('page_title', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('slug', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('emoji', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
        $table->addColumn('last_modified', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'page_id'], 'idx_coll_object_page');
        $table->addIndex(['object_uuid'], 'idx_coll_object');
        $table->addIndex(['register_id'], 'idx_coll_register');
        $table->addIndex(['schema_id'], 'idx_coll_schema');

        $output->info('Created openregister_collective_links table (Tier-2 schema)');
    }//end createCollectiveLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing collective_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendCollectiveLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_collective_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_coll_schema');
            $output->info('Added schema_id column to openregister_collective_links');
            $changed = true;
        }

        if ($table->hasColumn('slug') === false) {
            $table->addColumn('slug', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added slug column to openregister_collective_links');
            $changed = true;
        }

        if ($table->hasColumn('emoji') === false) {
            $table->addColumn('emoji', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => null]);
            $output->info('Added emoji column to openregister_collective_links');
            $changed = true;
        }

        if ($table->hasColumn('last_modified') === false) {
            $table->addColumn('last_modified', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added last_modified column to openregister_collective_links');
            $changed = true;
        }

        if ($table->hasColumn('url') === false) {
            $table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
            $output->info('Added url column to openregister_collective_links');
            $changed = true;
        }

        return $changed;
    }//end extendCollectiveLinksTable()
}//end class
