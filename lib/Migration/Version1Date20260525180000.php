<?php

/**
 * Tier-2 analytics (NC Analytics) integration migration.
 *
 * Ensures the `openregister_analytics_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - report_id (integer, not null) — `analytics_report` row id
 *  - report_title (string 255, not null) — cached report name
 *  - report_type (string 64, nullable) — cached report type/datasource
 *  - subheader (string 255, nullable) — cached report subheader
 *  - created_at (datetime, nullable) — cached report created timestamp
 *  - modified_at (datetime, nullable) — cached report modified timestamp
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 talk/polls/email/forms/deck/flow/
 * photos link tables; the wrapping `AnalyticsLinkService` replaces the
 * `[or:{uuid}]` report-name marker convention used by the Tier-1
 * `AnalyticsProvider` with a proper persistence layer so links survive
 * report renames.
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
 * Tier-2 analytics-links table — create-or-extend.
 */
class Version1Date20260525180000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_analytics_links') === false) {
            $this->createAnalyticsLinksTable(schema: $schema, output: $output);
            $changed = true;
        }

        if ($schema->hasTable('openregister_analytics_links') === true
            && $this->extendAnalyticsLinksTable(schema: $schema, output: $output) === true
        ) {
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()

    /**
     * Create the openregister_analytics_links table at the Tier-2 shape.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return void
     */
    private function createAnalyticsLinksTable(ISchemaWrapper $schema, IOutput $output): void
    {
        $table = $schema->createTable('openregister_analytics_links');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
        $table->addColumn('report_id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('report_title', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('report_type', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('subheader', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('created_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('modified_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['object_uuid', 'report_id'], 'idx_analytics_object_report');
        $table->addIndex(['object_uuid'], 'idx_analytics_object');
        $table->addIndex(['register_id'], 'idx_analytics_register');
        $table->addIndex(['schema_id'], 'idx_analytics_schema');

        $output->info('Created openregister_analytics_links table (Tier-2 schema)');
    }//end createAnalyticsLinksTable()

    /**
     * Add any missing Tier-2 columns to an existing analytics_links table.
     *
     * @param ISchemaWrapper $schema The schema wrapper
     * @param IOutput        $output Migration output
     *
     * @return bool True when a column was added.
     */
    private function extendAnalyticsLinksTable(ISchemaWrapper $schema, IOutput $output): bool
    {
        $table   = $schema->getTable('openregister_analytics_links');
        $changed = false;

        if ($table->hasColumn('schema_id') === false) {
            $table->addColumn(
                'schema_id',
                Types::BIGINT,
                ['notnull' => false, 'unsigned' => true, 'default' => null]
            );
            $table->addIndex(['schema_id'], 'idx_analytics_schema');
            $output->info('Added schema_id column to openregister_analytics_links');
            $changed = true;
        }

        if ($table->hasColumn('report_type') === false) {
            $table->addColumn('report_type', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
            $output->info('Added report_type column to openregister_analytics_links');
            $changed = true;
        }

        if ($table->hasColumn('subheader') === false) {
            $table->addColumn('subheader', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
            $output->info('Added subheader column to openregister_analytics_links');
            $changed = true;
        }

        if ($table->hasColumn('created_at') === false) {
            $table->addColumn('created_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added created_at column to openregister_analytics_links');
            $changed = true;
        }

        if ($table->hasColumn('modified_at') === false) {
            $table->addColumn('modified_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
            $output->info('Added modified_at column to openregister_analytics_links');
            $changed = true;
        }

        return $changed;
    }//end extendAnalyticsLinksTable()
}//end class
