<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Storage for the approval consolidation (flow-approval-consolidation):
 *
 * - `openregister_task_sequences`: the ordered approval cycle. One row per
 *   attempt at a gated transition, holding the frozen template snapshot and
 *   the resolved tier, so a schema edit or an amount edit cannot re-shape an
 *   approval that is already running (design D-3).
 * - `openregister_tasks` gains `sequence_uuid`, `sequence_position` and
 *   `legacy_step_id`. The unique index on (sequence_uuid, sequence_position)
 *   enforces ordinal stability at the database, because two writers
 *   provisioning the same gated write is the concurrent case.
 * - `openregister_approval_steps` gains `migrated_task_uuid`: the
 *   reconciliation column the data migration writes, so migrated steps and
 *   the tasks they became reconcile by identity rather than by count. The
 *   legacy tables are NOT dropped.
 * - `openregister_flow_runs` gains an indexed `correlation_key`, populated
 *   when an await-signal step suspends, so a signal addressed by business key
 *   resolves through an index instead of a JSON scan (design D-7).
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
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the task-sequence table and the reconciliation and correlation columns.
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */
class Version1Date20260901180000 extends SimpleMigrationStep {

	/**
	 * The sequence table.
	 */
	private const TABLE_SEQUENCES = 'openregister_task_sequences';

	/**
	 * The task table.
	 */
	private const TABLE_TASKS = 'openregister_tasks';

	/**
	 * The legacy step table. Kept, not dropped: the reconciliation column
	 * lands on it and the rollback path reads it.
	 */
	private const TABLE_STEPS = 'openregister_approval_steps';

	/**
	 * The flow-run table.
	 */
	private const TABLE_RUNS = 'openregister_flow_runs';

	/**
	 * Apply the schema changes.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		$this->createSequenceTable(schema: $schema);
		$this->extendTaskTable(schema: $schema);
		$this->extendStepTable(schema: $schema);
		$this->extendRunTable(schema: $schema);

		return $schema;
	}//end changeSchema()

	/**
	 * Create `openregister_task_sequences`.
	 *
	 * @param ISchemaWrapper $schema The schema to change.
	 *
	 * @return void
	 */
	private function createSequenceTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable(tableName: self::TABLE_SEQUENCES) === true) {
			return;
		}

		$table = $schema->createTable(tableName: self::TABLE_SEQUENCES);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('template_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('template_version', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('template_snapshot', Types::JSON, ['notnull' => false]);
		$table->addColumn('anchor_object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('chain_key', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('requester_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('resolved_tier', Types::JSON, ['notnull' => false]);
		$table->addColumn('position_cursor', Types::INTEGER, ['notnull' => true, 'default' => 1]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 20]);
		$table->addColumn('outcome', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('run_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('node_id', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('opened_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->addColumn('closed_at', Types::DATETIME_MUTABLE, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_taskseq_uuid');
		$table->addIndex(['anchor_object_uuid', 'template_id'], 'or_taskseq_anchor');
		$table->addIndex(['status', 'template_id'], 'or_taskseq_status');
	}//end createSequenceTable()

	/**
	 * Add the sequence and reconciliation columns to `openregister_tasks`.
	 *
	 * @param ISchemaWrapper $schema The schema to change.
	 *
	 * @return void
	 */
	private function extendTaskTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable(tableName: self::TABLE_TASKS) === false) {
			return;
		}

		$table = $schema->getTable(tableName: self::TABLE_TASKS);
		if ($table->hasColumn('sequence_uuid') === false) {
			$table->addColumn('sequence_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		}

		if ($table->hasColumn('sequence_position') === false) {
			$table->addColumn('sequence_position', Types::INTEGER, ['notnull' => false]);
		}

		if ($table->hasColumn('legacy_step_id') === false) {
			$table->addColumn('legacy_step_id', Types::BIGINT, ['notnull' => false]);
		}

		if ($table->hasIndex('or_tasks_sequence') === false) {
			// UNIQUE: two positions must not share an ordinal within one
			// sequence. Rows with a null sequence_uuid stay unconstrained.
			$table->addUniqueIndex(['sequence_uuid', 'sequence_position'], 'or_tasks_sequence');
		}
	}//end extendTaskTable()

	/**
	 * Add `migrated_task_uuid` to the kept legacy step table.
	 *
	 * @param ISchemaWrapper $schema The schema to change.
	 *
	 * @return void
	 */
	private function extendStepTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable(tableName: self::TABLE_STEPS) === false) {
			return;
		}

		$table = $schema->getTable(tableName: self::TABLE_STEPS);
		if ($table->hasColumn('migrated_task_uuid') === false) {
			$table->addColumn('migrated_task_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		}
	}//end extendStepTable()

	/**
	 * Add the indexed `correlation_key` to `openregister_flow_runs`.
	 *
	 * @param ISchemaWrapper $schema The schema to change.
	 *
	 * @return void
	 */
	private function extendRunTable(ISchemaWrapper $schema): void {
		if ($schema->hasTable(tableName: self::TABLE_RUNS) === false) {
			return;
		}

		$table = $schema->getTable(tableName: self::TABLE_RUNS);
		if ($table->hasColumn('correlation_key') === false) {
			$table->addColumn('correlation_key', Types::STRING, ['notnull' => false, 'length' => 255]);
		}

		if ($table->hasIndex('or_flow_runs_correlation') === false) {
			$table->addIndex(['status', 'correlation_key'], 'or_flow_runs_correlation');
		}
	}//end extendRunTable()
}//end class
