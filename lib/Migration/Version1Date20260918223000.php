<?php

/**
 * A term can say what happens when it ends on a day nobody works.
 *
 * Adds `roll_to_working_day` to the timer, and the two columns that say what a
 * roll DID: `unrolled_at`, where the budget put the deadline, and `rolled_by`,
 * the name of the rule that moved it. Both go on the timer and on its event
 * ledger, because a handler reading a term that ends on Tuesday needs to see
 * that Monday was Tweede Paasdag, and an auditor reading the ledger a year
 * later needs the same sentence.
 *
 * 🔴 NULL MEANS `none`, AND `none` IS THE DEFAULT. Every timer already armed
 * keeps the deadline it has. A migration that rolled existing terms would move
 * deadlines with legal effect, retroactively, without anybody deciding to.
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
 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the roll and its explanation to timers and their events.
 *
 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
 */
class Version1Date20260918223000 extends SimpleMigrationStep {

	/**
	 * The timer table.
	 *
	 * @var string
	 */
	private const TABLE_TIMERS = 'openregister_flow_timers';

	/**
	 * The timer event ledger.
	 *
	 * @var string
	 */
	private const TABLE_EVENTS = 'openregister_flow_timer_events';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output The migration output.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The changed schema.
	 *
	 * @spec openspec/changes/end-date-roll-on-the-calendar/specs/flow-business-timers/spec.md#requirement-a-budget-may-roll-its-end-date-to-a-working-day
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: self::TABLE_TIMERS) === true) {
			$timers = $schema->getTable(self::TABLE_TIMERS);
			if ($timers->hasColumn('roll_to_working_day') === false) {
				$timers->addColumn('roll_to_working_day', Types::STRING, ['notnull' => false, 'length' => 16]);
				$output->info('Added roll_to_working_day to openregister_flow_timers');
			}

			if ($timers->hasColumn('unrolled_at') === false) {
				$timers->addColumn('unrolled_at', Types::DATETIME, ['notnull' => false]);
			}

			if ($timers->hasColumn('rolled_by') === false) {
				$timers->addColumn('rolled_by', Types::STRING, ['notnull' => false, 'length' => 255]);
			}
		}

		if ($schema->hasTable(tableName: self::TABLE_EVENTS) === true) {
			$events = $schema->getTable(self::TABLE_EVENTS);
			if ($events->hasColumn('unrolled_at') === false) {
				$events->addColumn('unrolled_at', Types::DATETIME, ['notnull' => false]);
			}

			if ($events->hasColumn('rolled_by') === false) {
				$events->addColumn('rolled_by', Types::STRING, ['notnull' => false, 'length' => 255]);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
