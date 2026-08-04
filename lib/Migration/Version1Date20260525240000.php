<?php

/**
 * Tier-2 OpenProject (external / OpenConnector-routed) integration migration.
 *
 * Ensures the `openregister_openproject_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - work_package_id (integer, not null) — OpenProject work-package id
 *  - subject (string 512, not null) — cached work-package subject
 *  - type (string 64, nullable) — cached work-package type label
 *  - status (string 64, nullable) — cached status label
 *  - priority (string 64, nullable) — cached priority label
 *  - assignee (string 255, nullable) — cached assignee label
 *  - project (string 255, nullable) — cached project label
 *  - url (string 512, nullable) — cached deep link
 *  - cached_at (datetime, nullable) — when the cache columns were refreshed
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 talk/polls/email/forms/deck/
 * flow/photos/collectives link tables. Unlike the NC-native link tables
 * the picker source for OpenProject is the external OpenProject instance
 * reached through OpenConnector (AD-4 / AD-22); the link row caches the
 * work-package metadata so the sidebar tab renders without a per-row
 * upstream roundtrip and historical references survive an OpenConnector
 * outage.
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
 * Tier-2 openproject-links table — create-or-extend.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260525240000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_openproject_links') === false) {
            $this->createOpenProjectLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_openproject_links') === true
            && $this->extendOpenProjectLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_openproject_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createOpenProjectLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_openproject_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('work_package_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('subject', Types::STRING, ['notnull' => true, 'length' => 512]);
        $table->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('priority', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('project', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
        $table->addColumn('cached_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'work_package_id'], 'idx_op_object_wp');
        $table->addIndex(['object_uuid'], 'idx_op_object');
        $table->addIndex(['register_id'], 'idx_op_register');
        $table->addIndex(['schema_id'], 'idx_op_schema');

        $output->info('Created openregister_openproject_links table (Tier-2 schema)');
    }//end createOpenProjectLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing openproject_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendOpenProjectLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_openproject_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_op_schema');
            $output->info('Added schema_id column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('type') === false) {
            $table->addColumn('type', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $output->info('Added type column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('status') === false) {
            $table->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $output->info('Added status column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('priority') === false) {
            $table->addColumn('priority', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $output->info('Added priority column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('assignee') === false) {
            $table->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added assignee column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('project') === false) {
            $table->addColumn('project', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added project column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('url') === false) {
            $table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 512, 'default' => null]);
            $output->info('Added url column to openregister_openproject_links');
            $changed = true;
        }

        if ($table->hasColumn('cached_at') === false) {
            $table->addColumn('cached_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added cached_at column to openregister_openproject_links');
            $changed = true;
        }

        return $changed;
    }//end extendOpenProjectLinksTable()
}//end class
