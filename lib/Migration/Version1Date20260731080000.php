<?php

/**
 * Creates `openregister_flow_state` — state a flow keeps BETWEEN runs.
 *
 * OR#2216. A scheduled flow starts blank on every tick. The flow object carries
 * only its definition (`nodes`, `edges`, `cron`, …) and `openregister_flow_runs`
 * carries `marking` / `items` / `context`, which are PER-RUN resumption state
 * discarded when the run ends.
 *
 * So a flow that polls every five minutes has nowhere to keep a counter, a
 * cursor, a capacity table or "what did I already process". Every such value
 * had to become a separate register object, which is how hydra's concurrency
 * cap ended up as object writes and walked into the lost-update documented in
 * OR#2212.
 *
 * WHY A TABLE RATHER THAN A COLUMN ON THE FLOW. `FlowScheduleService` already
 * keeps one piece of per-flow state — the last-fire timestamp — beside the flow
 * rather than on it, and says why: "so the flow object itself is never
 * rewritten". Writing state onto the flow object each tick would churn the
 * definition's version and audit history with machine noise, race with an
 * operator editing that flow in the UI (last-write-wins is fine for data and
 * wrong for a definition), and merge "what this flow does" with "what this flow
 * is currently holding". This generalises the scheduler's existing choice
 * instead of contradicting it.
 *
 * `flow_id` is UNIQUE: exactly one state row per flow. That is what lets a
 * writer claim it atomically rather than read-then-write, which is the mistake
 * this whole line of work exists to stop repeating.
 *
 * Strictly additive — a new table cannot fail on existing data. Idempotent:
 * created only when absent.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `openregister_flow_state` table.
 */
class Version1Date20260731080000 extends SimpleMigrationStep {

	/**
	 * The table holding per-flow state that survives between runs.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_flow_state';

	/**
	 * Create the table when it does not exist yet.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema accessor.
	 * @param array $options Migration options.
	 *
	 * @return null|ISchemaWrapper The modified schema, or null when unchanged.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
			@var ISchemaWrapper $schema
		*/

		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('flow_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		// The state itself. TEXT rather than a typed column set: what a flow
		// needs to remember is the flow author's business, and a schema here
		// would have to be migrated every time one of them needed a new field.
		$table->addColumn('state', Types::TEXT, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);

		// UNIQUE, not just indexed. One state row per flow is what allows a
		// claim to be settled by the database instead of by a read-then-write
		// in PHP — see OR#2212 for what the latter costs.
		$table->addUniqueIndex(['flow_id'], 'or_flowstate_flow_uq');

		return $schema;
	}//end changeSchema()
}//end class
