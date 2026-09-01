<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case layer's store: two tables, additive only.
 *
 * `openregister_case_items` holds one row per plan-item INSTANCE, anchored to
 * an OpenRegister object by the same triple a flow run and a task already
 * carry (`object_uuid`, `register_id`, `schema_id`). This is the replacement
 * for the reference implementation's single `casePlanState` string: a plan
 * item is a row with its own state column, so "which cases have an active
 * item of type X" is an indexed query and two caseworkers completing two
 * different items never overwrite each other.
 *
 * Three deliberate absences, same rule as `openregister_tasks`:
 *
 * - NO `overdue`, `days_until_due` or `days_overdue` column. Deadlines are
 *   CARRIED (`due_at`, `expires_at`, `doorlooptijd`, `servicenorm`) and
 *   never computed on here; that is flow-business-timers' work.
 * - NO case entity and NO case id. The anchoring object IS the case.
 * - NO column on `openregister_tasks`: the link runs plan item -> task via
 *   `realisation_uuid`, never the other way.
 *
 * `openregister_case_item_audit` is append-only. Its mapper exposes no update
 * and no delete, and deleting a plan item does not cascade into it.
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
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the plan-item and plan-item-audit tables.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
class Version1Date20260901150000 extends SimpleMigrationStep {

	/**
	 * The plan-item table.
	 */
	private const TABLE_ITEMS = 'openregister_case_items';

	/**
	 * The append-only audit table.
	 */
	private const TABLE_AUDIT = 'openregister_case_item_audit';

	/**
	 * Create both tables and their indexes.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the ISchemaWrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		$changed = false;

		if ($schema->hasTable(self::TABLE_ITEMS) === false) {
			$this->createItemsTable(schema: $schema);
			$changed = true;
		}

		if ($schema->hasTable(self::TABLE_AUDIT) === false) {
			$this->createAuditTable(schema: $schema);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		$output->info('Created the case-plan tables: ' . self::TABLE_ITEMS . ', ' . self::TABLE_AUDIT . '.');

		return $schema;
	}//end changeSchema()

	/**
	 * The plan-item table: design.md, Data model.
	 *
	 * @param ISchemaWrapper $schema The schema to add to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function createItemsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_ITEMS);

		// Identity.
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('item_key', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);

		// Anchor: the same triple FlowRun::subject_* and Task::object_* carry.
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false]);

		// Definition provenance. All nullable: an ad-hoc item has none.
		$table->addColumn('flow_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('flow_version', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('definition_item_key', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16]);

		// Structure.
		$table->addColumn('parent_item_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('plan_item_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);

		// Lifecycle. `is_terminal` is written in the same statement as `state`.
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 20]);
		$table->addColumn('is_terminal', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('entered_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('terminated_reason', Types::STRING, ['notnull' => false, 'length' => 512]);

		// Criteria.
		$table->addColumn('entry_criteria', Types::JSON, ['notnull' => false]);
		$table->addColumn('exit_criteria', Types::JSON, ['notnull' => false]);
		$table->addColumn('required', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
		$table->addColumn('discretionary', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('repetition', Types::JSON, ['notnull' => false]);

		// Realisation: the link runs FROM the plan item TO the task or run.
		$table->addColumn('realisation_kind', Types::STRING, ['notnull' => false, 'length' => 8]);
		$table->addColumn('realisation_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('realisation_count', Types::INTEGER, ['notnull' => true, 'default' => 1]);

		// Authorization. Named `authorization_rules` rather than the design's
		// `authorization` because AUTHORIZATION is a reserved word in the SQL
		// standard; the API serialises it as `authorization`.
		$table->addColumn('authorization_rules', Types::JSON, ['notnull' => false]);

		// Performer hints: passed to the task on realisation, never evaluated here.
		$table->addColumn('candidate_users', Types::JSON, ['notnull' => false]);
		$table->addColumn('candidate_groups', Types::JSON, ['notnull' => false]);
		$table->addColumn('candidate_role', Types::STRING, ['notnull' => false, 'length' => 128]);

		// Deadlines: carried, not computed.
		$table->addColumn('due_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('expires_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('doorlooptijd', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('servicenorm', Types::STRING, ['notnull' => false, 'length' => 64]);

		// Plan-level settings (root authorization, allowed results, the
		// write-through field mapping), frozen at plan creation and carried on
		// every row of the plan so the plan needs no record of its own.
		$table->addColumn('plan_settings', Types::JSON, ['notnull' => false]);

		// Stamps.
		$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->addColumn('updated', Types::DATETIME_MUTABLE, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_caseitem_uuid');
		// "What is open on this case."
		$table->addIndex(['object_uuid', 'is_terminal'], 'or_caseitem_obj_term');
		// The tree read.
		$table->addIndex(['object_uuid', 'parent_item_id'], 'or_caseitem_obj_parent');
		// "Which cases are stuck where."
		$table->addIndex(['plan_item_type', 'state'], 'or_caseitem_type_state');
		// The reverse lookup from a task or a run.
		$table->addIndex(['realisation_uuid'], 'or_caseitem_realisation');
		// A repetition cannot collide with itself.
		$table->addUniqueIndex(['object_uuid', 'item_key', 'realisation_count'], 'or_caseitem_obj_key_rep');
	}//end createItemsTable()

	/**
	 * The append-only audit table.
	 *
	 * @param ISchemaWrapper $schema The schema to add to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function createAuditTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_AUDIT);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('case_item_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('from_state', Types::STRING, ['notnull' => false, 'length' => 20]);
		$table->addColumn('to_state', Types::STRING, ['notnull' => false, 'length' => 20]);
		$table->addColumn('cause', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('cause_ref', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
		$table->addColumn('authorized', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
		$table->addColumn('created', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['case_item_id'], 'or_caseaudit_item');
	}//end createAuditTable()
}//end class
