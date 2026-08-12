<?php

/**
 * Data sync / harvesting — per-record tracking table.
 *
 * Creates `openregister_sync_records`, one row per source record across a
 * harvest execution, tracking it through the gather -> fetch -> import
 * stages with a status, content hash (change detection), raw fetched data,
 * error detail and attempt count (retry/backoff). Enables resume-after-
 * failure and detailed execution reporting per the data-sync-harvesting spec.
 *
 *  - id (bigint, PK, autoincrement)
 *  - uuid (string 36, not null)
 *  - source_id (bigint, not null) — owning source
 *  - execution_id (string 36, not null) — groups one sync run
 *  - external_id (string 255) — source record identifier
 *  - status (string 32, not null, default 'pending')
 *  - object_uuid (string 36) — local object created/updated
 *  - content_hash (string 64) — change detection
 *  - raw_data (text/json) — raw fetched payload
 *  - error_message (text) — failure detail
 *  - attempts (int, default 0) — retry counter
 *  - organisation (string 36) — tenant slice
 *  - created / updated (datetime, not null)
 *
 * Idempotent: creates the table only when absent.
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
 * Create the openregister_sync_records table.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
class Version1Date20260614110000 extends SimpleMigrationStep
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

        if ($schema->hasTable('openregister_sync_records') === true) {
            return null;
        }

        $table = $schema->createTable('openregister_sync_records');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('source_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
        $table->addColumn('execution_id', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('external_id', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'pending']);
        $table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('content_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
        $table->addColumn('raw_data', Types::TEXT, ['notnull' => false]);
        $table->addColumn('error_message', Types::TEXT, ['notnull' => false]);
        $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
        $table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 36]);
        $table->addColumn('created', Types::DATETIME, ['notnull' => true]);
        $table->addColumn('updated', Types::DATETIME, ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'idx_syncrec_uuid');
        $table->addIndex(['execution_id'], 'idx_syncrec_exec');
        $table->addIndex(['source_id', 'status'], 'idx_syncrec_src_status');
        $table->addIndex(['source_id', 'external_id'], 'idx_syncrec_src_ext');

        $output->info('Created openregister_sync_records table');

        return $schema;

    }//end changeSchema()
}//end class
