<?php

/**
 * Add `time_zone` to `openregister_flow_timer_events`, so each ledger row names
 * the zone of the calendar its timer counted in (calendar-time-zone 2.3).
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * A nullable column: rows written before it name no zone and carry no local dates.
 *
 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
 */
class Version1Date20261114180000 extends TargetedMigrationStep {

	/**
	 * The ledger table.
	 */
	private const TABLE_EVENTS = 'openregister_flow_timer_events';

	/**
	 * Add the column when the table exists and lacks it.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @spec openspec/changes/calendar-time-zone/specs/flow-business-timers/spec.md
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable(tableName: self::TABLE_EVENTS) === false) {
			return $schema;
		}

		$events = $schema->getTable(self::TABLE_EVENTS);
		if ($events->hasColumn('time_zone') === true) {
			return $schema;
		}

		$events->addColumn('time_zone', Types::STRING, ['notnull' => false, 'length' => 64]);
		$output->info('Added time_zone to '.self::TABLE_EVENTS);

		return $schema;
	}//end changeTargetedSchema()
}//end class
