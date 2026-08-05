<?php

/**
 * Creates the flow-run step table.
 *
 * A run already carried its per-node history, but as a `log` JSON blob on the
 * run row. That answers "what happened in this run" and nothing else: "which
 * node type fails most", "show me every failed step for this flow" or "what did
 * node X output last Tuesday" all require loading and walking every blob. One
 * row per node execution makes that history queryable, and gives retention
 * something it can prune per flow.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_flow_steps`.
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
class Version1Date20260803000100 extends SimpleMigrationStep
{
    /**
     * Create the flow-run step table.
     *
     * @param IOutput $output        Migration output.
     * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_flow_steps') === true) {
            $output->info(message: 'openregister_flow_steps already exists, skipping...');
            return null;
        }

        $table = $schema->createTable('openregister_flow_steps');

        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);

        // The run this step belongs to, by uuid rather than by row id: a run is
        // addressed by uuid everywhere else (API, sub-flow parent pointer), and
        // matching that keeps the join key the same one callers already hold.
        $table->addColumn('run_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
        $table->addColumn('flow_id', Types::STRING, ['notnull' => true, 'length' => 255]);

        $table->addColumn('node_id', Types::STRING, ['notnull' => true, 'length' => 255]);

        // The catalogue id exactly as the node registry publishes it
        // (`{app}.{node}`). Storing the resolved-and-namespaced form is what
        // makes "which node type fails" answerable across apps — and a bare id
        // here is now a detectable defect rather than a silent skip.
        $table->addColumn('node_type', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);

        // Position in the walk. Appended across a resume, never renumbered, so
        // a suspended-then-resumed run reads as one ordered history.
        $table->addColumn('sequence', Types::INTEGER, ['notnull' => true, 'default' => 0]);

        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
        $table->addColumn('started', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('finished', Types::DATETIME, ['notnull' => false, 'default' => null]);
        $table->addColumn('duration_ms', Types::INTEGER, ['notnull' => false, 'default' => null]);

        // What the node produced. JSON rather than TEXT because a step output is
        // structured and is read back into the builder's per-node trace badge.
        $table->addColumn('output', Types::JSON, ['notnull' => false, 'default' => null]);

        // TEXT rather than STRING: an error is a thrown message and will exceed
        // any length we pick, and a truncated error is worse than a long one.
        $table->addColumn('error', Types::TEXT, ['notnull' => false, 'default' => null]);

        $table->addColumn('created', Types::DATETIME, ['notnull' => false, 'default' => null]);

        $table->setPrimaryKey(['id']);

        // The run-detail read: every step of one run, in walk order.
        $table->addIndex(['run_uuid', 'sequence'], 'or_flowstep_run_idx');

        // The diagnostic read this table exists for: failures by node type.
        $table->addIndex(['node_type', 'status'], 'or_flowstep_type_idx');

        // Per-flow history, newest first.
        $table->addIndex(['flow_id', 'id'], 'or_flowstep_flow_idx');

        // Retention prunes by age, per flow.
        $table->addIndex(['created'], 'or_flowstep_created_idx');

        $output->info(message: 'Created openregister_flow_steps table');

        return $schema;

    }//end changeSchema()
}//end class
