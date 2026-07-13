<?php

/**
 * Scheduled report store migration.
 *
 * Creates `openregister_scheduled_reports` — infrastructure DB state for the
 * scheduled-report-jobs feature. Each row is a recurring `ExportService`
 * export configuration owned by one user:
 *
 *  - id (bigint, PK, autoincrement)
 *  - owner (string 64, not null) — owning Nextcloud uid
 *  - name (string 255, not null) — user-facing label
 *  - register_id (bigint, not null) — target register
 *  - schema_id (bigint, nullable) — target schema (required for csv format)
 *  - filters (text, nullable) — opaque JSON `@self.*` filter map
 *  - format (string 16, not null) — csv|excel|pdf
 *  - schedule_type (string 16, not null) — daily|weekly|monthly
 *  - schedule_hour (smallint, not null) — 0-23
 *  - schedule_day_of_week (smallint, nullable) — 0-6, required when weekly
 *  - schedule_day_of_month (smallint, nullable) — 1-28, required when monthly
 *  - delivery_folder (string 512, not null) — default 'Reports/'
 *  - enabled (boolean, not null) — default true
 *  - last_run_at (datetime, nullable)
 *  - last_status (string 16, nullable) — success|failed
 *  - last_error (text, nullable)
 *  - created_at (datetime, not null)
 *  - updated_at (datetime, not null)
 *
 * Indexes:
 *  - (owner) — owner-scoped listing
 *  - (enabled) — ScheduledReportJob's candidate scan
 *
 * This is explicitly NOT an OpenRegister object/register (ADR-001): the rows
 * are scheduling config with no business meaning. Idempotent: creates the
 * table only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the openregister_scheduled_reports table.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */
class Version1Date20260713000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process.
     * @param Closure                 $schemaClosure The schema closure.
     * @param array<array-key, mixed> $options       Migration options.
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_scheduled_reports') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_scheduled_reports');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
        $table->addColumn('filters', Types::TEXT, ['notnull' => false]);
        $table->addColumn('format', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('schedule_type', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('schedule_hour', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('schedule_day_of_week', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('schedule_day_of_month', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('delivery_folder', Types::STRING, ['notnull' => true, 'length' => 512, 'default' => 'Reports/']);
        $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
        $table->addColumn('last_run_at', Types::DATETIME, ['notnull' => false]);
        $table->addColumn('last_status', Types::STRING, ['notnull' => false, 'length' => 16]);
        $table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['owner'], 'idx_or_sched_report_owner');
        $table->addIndex(['enabled'], 'idx_or_sched_report_enabled');

        $output->info('Created openregister_scheduled_reports table');

        return $schema;
    }//end changeSchema()
}//end class
