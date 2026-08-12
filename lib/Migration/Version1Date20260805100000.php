<?php

/**
 * Adds the flow status and last-run columns to `openregister_flows`.
 *
 * Two questions could not be answered without reading run history, and one of
 * them could not be answered at all:
 *
 * 1. "Why did this flow not run?" A flow refused for a dead end produced NO
 *    `FlowRun` — there was nothing to read. A refused flow and a flow nobody
 *    had triggered were indistinguishable, which is the worst possible state
 *    for the one case where the author needs to be told something is wrong.
 * 2. "When did this last run, and how did it go?" Answerable only by querying
 *    the run table per flow, which the list view cannot do for every row.
 *
 * `status`/`status_message` answer the first, `last_run_*` the second.
 *
 * All six are nullable with no default and NO backfill. NULL on `last_run_*`
 * means "has never run", which is true of every existing row at the moment
 * this migration runs — inventing a value from the newest FlowRun would be
 * asserting a history this column did not record. NULL on `status` means "no
 * verdict", not "ok": a flow only gains a status when something has judged it.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `status`, `status_message` and the four `last_run_*` columns.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class Version1Date20260805100000 extends SimpleMigrationStep {
	/**
	 * Add the six nullable columns.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema, or null when unchanged.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_flows') === false) {
			$output->info('openregister_flows does not exist yet; nothing to alter.');
			return null;
		}

		$table = $schema->getTable('openregister_flows');
		$changed = false;

		// Short strings, not enums: the vocabulary is `ok`/`error` today, and a
		// database-level enum would need a migration to add a third value.
		$strings = [
			'status' => 32,
			'status_message' => 1024,
			'last_run_uuid' => 64,
			'last_run_status' => 32,
			'last_run_message' => 1024,
		];

		foreach ($strings as $column => $length) {
			if ($table->hasColumn($column) === true) {
				continue;
			}

			$table->addColumn(
				$column,
				Types::STRING,
				[
					'notnull' => false,
					'default' => null,
					'length' => $length,
				]
			);
			$changed = true;
		}//end foreach

		if ($table->hasColumn('last_run_at') === false) {
			$table->addColumn(
				'last_run_at',
				Types::DATETIME,
				[
					'notnull' => false,
					'default' => null,
				]
			);
			$changed = true;
		}

		if ($changed === true) {
			$output->info('Added flow status and last-run columns to openregister_flows.');
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
