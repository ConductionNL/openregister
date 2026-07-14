<?php

/**
 * Scheduled report email-delivery migration.
 *
 * Adds two nullable/defaulted columns to the already-shipped
 * `openregister_scheduled_reports` table (see `Version1Date20260713000000`)
 * so a scheduled report can be delivered by email in addition to (or
 * instead of) Nextcloud Files:
 *
 *  - delivery_mode (string 16, not null, default 'files') — files|email|both.
 *    Defaulting to 'files' means every row created before this migration
 *    keeps its exact prior (Files-only) behaviour with no data migration.
 *  - recipients (text, nullable) — opaque JSON array of recipient email
 *    addresses. Null/empty means "the owner's own Nextcloud email address,
 *    resolved at run time" (see `ScheduledReportService::resolveRecipients()`).
 *
 * This is deliberately a separate migration file, not an edit to the
 * already-shipped `Version1Date20260713000000` — per the repo's migration
 * convention, a shipped migration is never edited after merge. Idempotent:
 * adds each column only when absent, and is a no-op entirely if the base
 * table doesn't exist yet (fresh installs get both columns from whichever
 * migration runs first via `hasColumn()` checks).
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
 * @spec openspec/changes/scheduled-report-email-delivery/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `delivery_mode`/`recipients` columns to `openregister_scheduled_reports`.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 *
 * @spec openspec/changes/scheduled-report-email-delivery/specs/scheduled-report-jobs/spec.md
 */
class Version1Date20260714000000 extends SimpleMigrationStep
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
     * @spec openspec/changes/scheduled-report-email-delivery/specs/scheduled-report-jobs/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable(tableName: 'openregister_scheduled_reports') === false) {
            return null;
        }

        $table   = $schema->getTable(tableName: 'openregister_scheduled_reports');
        $changed = false;

        if ($table->hasColumn(name: 'delivery_mode') === false) {
            $table->addColumn(
                name: 'delivery_mode',
                typeName: Types::STRING,
                options: [
                    'notnull' => true,
                    'length'  => 16,
                    'default' => 'files',
                ]
            );
            $changed = true;
        }

        if ($table->hasColumn(name: 'recipients') === false) {
            $table->addColumn(
                name: 'recipients',
                typeName: Types::TEXT,
                options: ['notnull' => false]
            );
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        $output->info('Added delivery_mode/recipients to openregister_scheduled_reports');

        return $schema;
    }//end changeSchema()
}//end class
