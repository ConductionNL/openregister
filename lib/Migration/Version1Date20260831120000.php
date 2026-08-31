<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fleet-generic task store: four tables, additive only.
 *
 * `openregister_tasks` is the ONE task the fleet gets instead of the 23
 * conflicting shapes inventoried on 2026-08-22. Three deliberate absences are
 * part of the schema, not omissions:
 *
 * - NO `overdue`, `days_until_due` or `days_overdue` column. Overdue is a
 *   clock-derived fact; three fleet schemas store it by hand today and it is
 *   wrong between writes. It is computed on read, always.
 * - NO app-named column. The nineteenth consuming entity kind is absorbed by
 *   `openregister_task_relations` with an INSERT, never a migration.
 * - NO closed database-level enum on `performer_type` or `state`: both are
 *   plain strings validated at the service boundary, so an additional
 *   performer type (an external portal party is already being added by an
 *   ADR-098 amendment) is a vocabulary change, not a migration.
 *
 * `openregister_task_candidates` is the index half of the candidate pool: the
 * `candidate_users`/`candidate_groups` JSON on the task is the readable
 * record, and these rows are what the pooled inbox joins against. One write
 * path maintains both inside one transaction (TaskService).
 *
 * `openregister_task_audit` is append-only. No update or delete path exists in
 * its mapper, and deleting a task does not cascade into it.
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the task, candidate-index, relation and audit tables.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */
class Version1Date20260831120000 extends SimpleMigrationStep {

	/**
	 * The task table.
	 */
	private const TABLE_TASKS = 'openregister_tasks';

	/**
	 * The candidate-pool index table.
	 */
	private const TABLE_CANDIDATES = 'openregister_task_candidates';

	/**
	 * The typed relation table.
	 */
	private const TABLE_RELATIONS = 'openregister_task_relations';

	/**
	 * The append-only audit table.
	 */
	private const TABLE_AUDIT = 'openregister_task_audit';

	/**
	 * Create the four tables and their indexes.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the ISchemaWrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		$changed = false;

		if ($schema->hasTable(self::TABLE_TASKS) === false) {
			$this->createTasksTable(schema: $schema);
			$changed = true;
		}

		if ($schema->hasTable(self::TABLE_CANDIDATES) === false) {
			$this->createCandidatesTable(schema: $schema);
			$changed = true;
		}

		if ($schema->hasTable(self::TABLE_RELATIONS) === false) {
			$this->createRelationsTable(schema: $schema);
			$changed = true;
		}

		if ($schema->hasTable(self::TABLE_AUDIT) === false) {
			$this->createAuditTable(schema: $schema);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * The task table itself.
	 *
	 * Grouped by concern as in design.md — Data model. Everything nullable
	 * unless the design says otherwise.
	 *
	 * @param ISchemaWrapper $schema The schema to add the table to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	private function createTasksTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_TASKS);

		// Identity.
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		// `task_key`, not `key`: KEY is a reserved word on MySQL/MariaDB and
		// this table must create identically on every supported database.
		$table->addColumn('task_key', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		$table->addColumn('metadata', Types::JSON, ['notnull' => false]);

		// Provenance. run_uuid and node_id are OPTIONAL: a standalone task is
		// first-class (design D-3). definition_version copies the run's pinned
		// flow version at creation; it ships now (flow definition versioning
		// landed with openregister#3047) and is written once the user-task
		// node exists to write it.
		$table->addColumn('run_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('node_id', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('definition_version', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('app_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('workflow_step_id', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64]);

		// Lifecycle. `state` holds one of the six CMMN plan-item states;
		// `is_terminal` is materialised alongside it in the same statement.
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 20]);
		$table->addColumn('is_terminal', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('last_action', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('outcome', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('blocked_reason', Types::STRING, ['notnull' => false, 'length' => 512]);

		// Performer.
		$table->addColumn('performer_type', Types::STRING, ['notnull' => true, 'length' => 20]);
		$table->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('candidate_users', Types::JSON, ['notnull' => false]);
		$table->addColumn('candidate_groups', Types::JSON, ['notnull' => false]);
		$table->addColumn('candidate_role', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('routing_strategy', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('routing_fallback', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('on_behalf_of', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('mandate', Types::STRING, ['notnull' => false, 'length' => 512]);
		$table->addColumn('requester', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('watchers', Types::JSON, ['notnull' => false]);

		// Timing. due_at ADVISES, expires_at ENFORCES (design D-4). The SLA,
		// compliance, suspension and recurrence columns are stored here and
		// interpreted by flow-business-timers.
		$table->addColumn('start_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('due_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('sla_value', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('sla_unit', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('compliance_period_days', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('suspended_until', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('recurrence', Types::STRING, ['notnull' => false, 'length' => 255]);

		// Priority: one normalised scale.
		$table->addColumn('priority', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'normal']);

		// Anchor: ONE generic subject reference. Everything else goes through
		// the relation table (design D-6).
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false]);

		// Template freeze.
		$table->addColumn('template_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('template_version', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('template_snapshot', Types::JSON, ['notnull' => false]);

		// Checklist and responses.
		$table->addColumn('checklist', Types::JSON, ['notnull' => false]);
		$table->addColumn('responses', Types::JSON, ['notnull' => false]);
		$table->addColumn('percent_complete', Types::INTEGER, ['notnull' => false]);

		// Completion metadata.
		$table->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('completed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('result_text', Types::TEXT, ['notnull' => false]);
		$table->addColumn('comment', Types::TEXT, ['notnull' => false]);
		$table->addColumn('evidence', Types::JSON, ['notnull' => false]);
		$table->addColumn('override_reason', Types::STRING, ['notnull' => false, 'length' => 512]);

		// Hierarchy: two columns because planix genuinely has two hierarchies.
		$table->addColumn('parent_task_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('epic_task_id', Types::BIGINT, ['notnull' => false]);

		// Audit stamps.
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_tasks_uuid');
		// "My open work": the inbox's hot path.
		$table->addIndex(['assignee', 'is_terminal', 'due_at'], 'or_tasks_assignee_open');
		// The overdue sweep.
		$table->addIndex(['is_terminal', 'due_at'], 'or_tasks_open_due');
		// "Tasks on this object."
		$table->addIndex(['object_uuid'], 'or_tasks_object');
		// Cancellation propagation.
		$table->addIndex(['run_uuid'], 'or_tasks_run');
	}//end createTasksTable()

	/**
	 * The candidate-pool index table.
	 *
	 * @param ISchemaWrapper $schema The schema to add the table to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-performer-model-spans-people-groups-agents-and-workers
	 */
	private function createCandidatesTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_CANDIDATES);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('task_id', Types::BIGINT, ['notnull' => true]);
		// 'user' | 'group' | 'role'.
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('ref', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->setPrimaryKey(['id']);
		// The pooled-inbox join: "unclaimed tasks in any group I am in".
		$table->addIndex(['kind', 'ref'], 'or_task_cand_kind_ref');
		$table->addIndex(['task_id'], 'or_task_cand_task');
	}//end createCandidatesTable()

	/**
	 * The typed relation table.
	 *
	 * @param ISchemaWrapper $schema The schema to add the table to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	private function createRelationsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_RELATIONS);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('task_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['object_uuid', 'role'], 'or_task_rel_object_role');
		$table->addIndex(['task_id'], 'or_task_rel_task');
	}//end createRelationsTable()

	/**
	 * The append-only audit table.
	 *
	 * @param ISchemaWrapper $schema The schema to add the table to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-the-task-audit-is-append-only-and-names-the-performer-type
	 */
	private function createAuditTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_AUDIT);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('task_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('state_after', Types::STRING, ['notnull' => false, 'length' => 20]);
		$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('performer_type', Types::STRING, ['notnull' => false, 'length' => 20]);
		$table->addColumn('on_behalf_of', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('mandate', Types::STRING, ['notnull' => false, 'length' => 512]);
		$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
		// Denials are recorded too: authorized=false is an audit row, not an
		// absence of one.
		$table->addColumn('authorized', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['task_id'], 'or_task_audit_task');
	}//end createAuditTable()
}//end class
