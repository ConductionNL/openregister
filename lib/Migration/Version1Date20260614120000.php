<?php

/**
 * Schema versioning & object migration — changelog + run tables.
 *
 * Creates three tables backing the schema-migration capability:
 *
 *  - openregister_schema_changelog: one classified entry per schema-version
 *    change (classification, typed change list, actor, acknowledgement).
 *  - openregister_schema_runs: revalidation / migration / rollback runs over
 *    a schema's object population (state machine, proposed definition, plan,
 *    progress counters, resumable cursor, summary report).
 *  - openregister_schema_run_entries: per-object result side table (outcome,
 *    message, pre/post content-version ids for rollback) so a run row stays
 *    small for large populations.
 *
 * Idempotent: each table is created only when absent.
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
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the schema changelog and run tables.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
class Version1Date20260614120000 extends SimpleMigrationStep
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
     * @spec openspec/specs/schema-migration/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_schema_changelog') === false) {
            $table = $schema->createTable('openregister_schema_changelog');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 32]);
            $table->addColumn('classification', Types::STRING, ['notnull' => false, 'length' => 16]);
            $table->addColumn('changes', Types::TEXT, ['notnull' => false]);
            $table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('acknowledged_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('acknowledged_at', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['schema_id'], 'idx_or_schchg_schema');

            $output->info('Created openregister_schema_changelog table');
        }//end if

        if ($schema->hasTable('openregister_schema_runs') === false) {
            $table = $schema->createTable('openregister_schema_runs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
            $table->addColumn('schema_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'draft']);
            $table->addColumn('proposed_definition', Types::TEXT, ['notnull' => false]);
            $table->addColumn('plan', Types::TEXT, ['notnull' => false]);
            $table->addColumn('options', Types::TEXT, ['notnull' => false]);
            $table->addColumn('processed', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('total', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('cursor', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
            $table->addColumn('report', Types::TEXT, ['notnull' => false]);
            $table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('rolled_back_from', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'idx_or_schrun_uuid');
            $table->addIndex(['schema_id', 'state'], 'idx_or_schrun_sch_state');

            $output->info('Created openregister_schema_runs table');
        }//end if

        if ($schema->hasTable('openregister_schema_run_entries') === false) {
            $table = $schema->createTable('openregister_schema_run_entries');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
            $table->addColumn('outcome', Types::STRING, ['notnull' => false, 'length' => 16]);
            $table->addColumn('message', Types::TEXT, ['notnull' => false]);
            $table->addColumn('pre_version', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('post_version', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('pre_data', Types::TEXT, ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['run_id'], 'idx_or_schrunent_run');
            $table->addIndex(['run_id', 'outcome'], 'idx_or_schrunent_outcome');

            $output->info('Created openregister_schema_run_entries table');
        }//end if

        return $schema;

    }//end changeSchema()
}//end class
