<?php

/**
 * Create the durable business-timer store: `openregister_flow_timers`,
 * `openregister_flow_timer_fires` and `openregister_flow_timer_events`.
 *
 * FLOW-BUSINESS-TIMERS (openspec/changes/flow-business-timers). The timer
 * table holds a BUDGET and a suspension ledger, not a target instant
 * (design D-2): `budget_value`/`budget_unit` say what the term is,
 * `consumed_value` how much of it has run, `running_since` when the current
 * running segment began, and `fire_at`/`next_rung_at` are materialised
 * derivations of those inputs so the sweep can be an index range scan
 * (design D-8). There is deliberately NO `overdue`, `is_overdue` or
 * `days_overdue` column: overdue is `state = 'armed' AND fire_at < now`,
 * computed on read, and a suspended timer has a NULL `fire_at` so it cannot
 * satisfy it.
 *
 * The fire ledger is UNIQUE on `(timer_uuid, rung_key)`: the constraint the
 * whole at-most-once argument for escalation rungs rests on (design D-7). The
 * event table is append-only evidence with no update or delete path.
 *
 * Strictly additive: no existing table is altered. Idempotent: each table is
 * created only when absent. Every index name stays within the 30-character
 * limit, matching `or_flowtrig_match_idx`.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the three business-timer tables.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */
class Version1Date20260901193000 extends SimpleMigrationStep {
	/**
	 * The timer table.
	 *
	 * @var string
	 */
	public const TABLE_TIMERS = 'openregister_flow_timers';

	/**
	 * The rung dedup ledger.
	 *
	 * @var string
	 */
	public const TABLE_FIRES = 'openregister_flow_timer_fires';

	/**
	 * The append-only evidence log.
	 *
	 * @var string
	 */
	public const TABLE_EVENTS = 'openregister_flow_timer_events';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(self::TABLE_TIMERS) === false) {
			$this->createTimers(schema: $schema);
			$changed = true;
		}

		if ($schema->hasTable(self::TABLE_FIRES) === false) {
			$this->createFires(schema: $schema);
			$changed = true;
		}

		if ($schema->hasTable(self::TABLE_EVENTS) === false) {
			$this->createEvents(schema: $schema);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * The timer table (design.md, Data model).
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	private function createTimers(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_TIMERS);

		// Identity.
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('title', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('metadata', Types::JSON, ['notnull' => false]);

		// Subject: the row the timer measures. Provenance is optional.
		$table->addColumn('subject_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('subject_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('organisation', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('run_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('node_id', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('app_id', Types::STRING, ['notnull' => false, 'length' => 64]);

		// Purpose: due advises, expiry enforces, and only wettelijk may enforce.
		$table->addColumn('purpose', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('legal_effect', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('on_expiry', Types::STRING, ['notnull' => false, 'length' => 128]);

		// Anchor: stored, so a moved anchor can re-arm (design D-4).
		$table->addColumn('anchor_event', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('anchor_offset', Types::INTEGER, ['notnull' => false]);
		$table->addColumn('anchor_offset_unit', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('anchor_at', Types::DATETIME, ['notnull' => true]);

		// Budget and the suspension ledger (design D-2).
		$table->addColumn('budget_value', Types::DECIMAL, ['notnull' => true, 'precision' => 12, 'scale' => 4]);
		$table->addColumn('budget_unit', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('consumed_value', Types::DECIMAL, ['notnull' => true, 'precision' => 12, 'scale' => 4, 'default' => 0]);
		$table->addColumn('running_since', Types::DATETIME, ['notnull' => false]);

		// Derived, maintained by ONE private method (design D-2, D-8).
		$table->addColumn('fire_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('next_rung_at', Types::DATETIME, ['notnull' => false]);

		// Calendar and ladder.
		$table->addColumn('calendar_slug', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('ladder_slug', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('escalation_rules', Types::JSON, ['notnull' => false]);

		// Suspension evidence (reporting, NOT arithmetic inputs).
		$table->addColumn('suspended_since', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('suspend_reason', Types::STRING, ['notnull' => false, 'length' => 512]);
		$table->addColumn('suspended_total_seconds', Types::BIGINT, ['notnull' => true, 'default' => 0]);

		// Extension bound.
		$table->addColumn('extension_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('extension_max', Types::INTEGER, ['notnull' => true, 'default' => 1]);

		// Lifecycle. No overdue column, by requirement.
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('supersedes_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('fired_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('breached', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('cancelled_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('cancel_reason', Types::STRING, ['notnull' => false, 'length' => 512]);

		// Stamps.
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_flowtimer_uuid_idx');
		// The two bounded range scans of the sweep.
		$table->addIndex(['state', 'fire_at'], 'or_flowtimer_due_idx');
		$table->addIndex(['state', 'next_rung_at'], 'or_flowtimer_rung_idx');
		// Cancellation by subject, and the run provenance read.
		$table->addIndex(['subject_type', 'subject_uuid', 'state'], 'or_flowtimer_subj_idx');
		$table->addIndex(['run_uuid'], 'or_flowtimer_run_idx');
	}//end createTimers()

	/**
	 * The rung dedup ledger, unique per (timer, rung).
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	private function createFires(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_FIRES);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('timer_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('rung_key', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('fired_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('transition_action', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('recipient_roles', Types::JSON, ['notnull' => false]);
		$table->addColumn('priority', Types::STRING, ['notnull' => false, 'length' => 16]);
		$table->addColumn('inherited', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		// The constraint the at-most-once argument rests on.
		$table->addUniqueIndex(['timer_uuid', 'rung_key'], 'or_flowtimfire_uq');
	}//end createFires()

	/**
	 * The append-only evidence log.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	private function createEvents(ISchemaWrapper $schema): void {
		$table = $schema->createTable(self::TABLE_EVENTS);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('timer_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 1024]);
		$table->addColumn('prior_fire_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('new_fire_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('days_impact', Types::DECIMAL, ['notnull' => false, 'precision' => 12, 'scale' => 4]);
		$table->addColumn('basis', Types::STRING, ['notnull' => false, 'length' => 128]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['timer_uuid', 'created'], 'or_flowtimev_timer_idx');
	}//end createEvents()
}//end class
