<?php

/**
 * Creates the flow-run table.
 *
 * A run has to outlive the request that started it, or a Wait step, a
 * sub-flow, run history, retry, and executing a flow off the triggering
 * request are all impossible. This is the table that makes those possible.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_flow_runs`.
 */
class Version1Date20260724120000 extends SimpleMigrationStep
{
    /**
     * Create the flow-run table.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flow_runs') === true) {
            $output->info(message: 'openregister_flow_runs already exists, skipping...');
            return null;
        }

        $table = $schema->createTable('openregister_flow_runs');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
        $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('flow_id', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);

        // The Petri-net marking, the item list and the run-level context are
        // the three things a resume needs; the log is what a human needs.
        $table->addColumn('marking', Types::JSON, ['notnull' => false, 'default' => null]);
        $table->addColumn('items', Types::JSON, ['notnull' => false, 'default' => null]);
        $table->addColumn('context', Types::JSON, ['notnull' => false, 'default' => null]);
        $table->addColumn('log', Types::JSON, ['notnull' => false, 'default' => null]);

        $table->addColumn('subject_uuid', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('subject_register', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('subject_schema', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('triggered_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
        $table->addColumn('trigger', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
        $table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);

        // TEXT rather than STRING: an error is a thrown message and will exceed
        // any length we pick, and a truncated error is worse than a long one.
        $table->addColumn('error', Types::TEXT, ['notnull' => false, 'default' => null]);

        $table->addColumn('resume_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('parent_run_uuid', Types::STRING, ['notnull' => false, 'length' => 36, 'default' => null]);
        $table->addColumn('created', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('updated', Types::DATETIME, ['notnull' => false, 'default' => null]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'or_flowrun_uuid_idx');

        // The queue worker's two hot reads: what is queued, and what is due.
        // `status` leads both, so one composite index serves each query.
        $table->addIndex(['status', 'id'], 'or_flowrun_status_idx');
        $table->addIndex(['status', 'resume_at'], 'or_flowrun_due_idx');

        // Run history is listed per flow, newest first.
        $table->addIndex(['flow_id', 'id'], 'or_flowrun_flow_idx');

        // Retention prunes terminal runs by age.
        $table->addIndex(['updated'], 'or_flowrun_updated_idx');

        // A sub-flow's parent, for assembling a run tree.
        $table->addIndex(['parent_run_uuid'], 'or_flowrun_parent_idx');

        $output->info(message: 'Created openregister_flow_runs table');

        return $schema;

    }//end changeSchema()
}//end class
