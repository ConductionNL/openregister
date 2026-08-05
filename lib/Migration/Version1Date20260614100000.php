<?php

/**
 * Data sync / harvesting — additive sync columns on openregister_sources.
 *
 * Extends the existing `openregister_sources` table with the sync-pipeline
 * fields described by the data-sync-harvesting spec, mirroring the sync
 * fields already present on the Configuration entity. All columns are
 * additive and nullable (or carry safe defaults) so existing sources are
 * untouched: a source with `sync_enabled = false` behaves exactly as it
 * did before this migration.
 *
 *  - sync_enabled (bool, default false)
 *  - sync_schedule (string 64) — cron expression (informational)
 *  - sync_interval (int) — interval in hours used by the timed job
 *  - last_sync_date (datetime) — last successful sync checkpoint
 *  - last_sync_status (string 16) — success|partial|failed|running
 *  - last_sync_token (string 255) — incremental delta token / cursor
 *  - auth_type (string 32) — none|apikey|basic|oauth2|certificate
 *  - auth_config (text/json) — encrypted credential blob
 *  - mapping_id (int) — reference to a Mapping entity
 *  - target_register (string 255) — register slug/id imported into
 *  - target_schema (string 255) — schema slug/id validated against
 *  - conflict_strategy (string 16) — source-wins|local-wins|newest-wins|manual
 *  - delete_strategy (string 16) — soft-delete|hard-delete|ignore
 *  - batch_size (int) — records per processing chunk
 *
 * Idempotent: each column is added only when absent.
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
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add sync-pipeline columns to openregister_sources.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class Version1Date20260614100000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput                 $output        Output for the migration process
     * @param Closure                 $schemaClosure The schema closure
     * @param array<array-key, mixed> $options       Migration options
     *
     * @return ISchemaWrapper|null
     *
     * @spec openspec/specs/data-sync-harvesting/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_sources') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_sources');

        // Sync-pipeline columns, in declaration order. Driven from a spec list
        // so this method carries one branch per concern rather than one per
        // column (fourteen independent ifs put NPath complexity at 131072 and
        // cyclomatic complexity at 18).
        $newColumns = [
            ['sync_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => false]],
            ['sync_schedule', Types::STRING, ['notnull' => false, 'length' => 64]],
            ['sync_interval', Types::INTEGER, ['notnull' => false, 'unsigned' => true]],
            ['last_sync_date', Types::DATETIME, ['notnull' => false]],
            ['last_sync_status', Types::STRING, ['notnull' => false, 'length' => 16]],
            ['last_sync_token', Types::STRING, ['notnull' => false, 'length' => 255]],
            ['auth_type', Types::STRING, ['notnull' => false, 'length' => 32]],
            ['auth_config', Types::TEXT, ['notnull' => false]],
            ['mapping_id', Types::INTEGER, ['notnull' => false, 'unsigned' => true]],
            ['target_register', Types::STRING, ['notnull' => false, 'length' => 255]],
            ['target_schema', Types::STRING, ['notnull' => false, 'length' => 255]],
            ['conflict_strategy', Types::STRING, ['notnull' => false, 'length' => 16]],
            ['delete_strategy', Types::STRING, ['notnull' => false, 'length' => 16]],
            ['batch_size', Types::INTEGER, ['notnull' => false, 'unsigned' => true]],
        ];

        $changed = false;

        foreach ($newColumns as [$columnName, $columnType, $columnOptions]) {
            if ($table->hasColumn($columnName) === true) {
                continue;
            }

            $table->addColumn($columnName, $columnType, $columnOptions);
            $changed = true;
        }

        if ($table->hasIndex('sources_sync_enabled_idx') === false) {
            $table->addIndex(['sync_enabled'], 'sources_sync_enabled_idx');
            $changed = true;
        }

        if ($changed === true) {
            $output->info('Added data-sync columns to openregister_sources table');
            return $schema;
        }

        return null;

    }//end changeSchema()
}//end class
