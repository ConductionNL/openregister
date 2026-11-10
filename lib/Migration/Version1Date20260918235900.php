<?php

/**
 * The index the calendar recompute's two reads need.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Indexes the predicate the calendar recompute actually reads on.
 *
 * 🔑 THE TASK ASKED FOR TWO INDEXES; THE SOURCE NEEDS ONE, AND SAYING SO IS THE
 * POINT. `FlowTimerMapper` has exactly two calendar reads,
 * `findOpenByCalendarSlug()` and `countOpenByCalendarSlug()`, and BOTH filter on
 * the same pair: `calendar_slug` and `state`. One composite index serves both.
 * A second index on `organisation` was in the plan, and no query filters on it,
 * so it would be an index nothing reads: write cost on every timer armed, for
 * nobody.
 *
 * 🔴 WHAT THIS CHANGES IS THE COST, NOT THE ANSWER. Without it the recompute
 * paged the open timers by id, which is an index read with a resumable cursor
 * over a small set rather than a scan of every timer ever armed. So this is a
 * speed-up on a correct job, not a fix for a wrong one, and it must not be
 * described as the latter.
 *
 * The existing `or_flowtimer_due_idx` on `(state, fire_at)` does not serve these
 * reads: it leads on `state`, which is two values here, so it cannot narrow to
 * one calendar.
 */
class Version1Date20260918235900 extends SimpleMigrationStep {

	/**
	 * The timers table.
	 */
	private const TABLE = 'openregister_flow_timers';

	/**
	 * The index name.
	 */
	private const INDEX = 'or_flowtimer_cal_idx';

	/**
	 * Add the calendar index.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);

		// Idempotent, like every other step here: a re-run on an instance that
		// already has it must not fail the upgrade.
		if ($table->hasIndex(self::INDEX) === true) {
			return $schema;
		}

		if ($table->hasColumn('calendar_slug') === false || $table->hasColumn('state') === false) {
			// Nothing to index yet. Said rather than assumed, because a missing
			// column here would otherwise throw during an upgrade on an
			// instance that predates the calendar columns.
			$output->info('openregister_flow_timers has no calendar columns yet; skipping the calendar index');
			return $schema;
		}

		// `calendar_slug` LEADS. It is the selective half: one calendar out of
		// many, against a `state` that is two values. Leading on state would
		// give an index that narrows almost nothing.
		$table->addIndex(['calendar_slug', 'state'], self::INDEX);
		$output->info('Added ' . self::INDEX . ' to ' . self::TABLE);

		return $schema;
	}//end changeSchema()
}//end class
