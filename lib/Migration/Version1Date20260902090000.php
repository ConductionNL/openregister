<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Storage for task expiry enforcement and configurable outcomes
 * (task-expiry-and-outcomes, harvested from integriq's HITL semantics):
 *
 * - `openregister_tasks` gains `on_timeout` and `on_reject`: each holds one
 *   value of the reserved timer-outcome vocabulary (skip|error|dead_letter),
 *   null meaning "no declared behaviour" — which is exactly the pre-change
 *   behaviour, so existing rows change nothing.
 * - `or_tasks_open_expiry (is_terminal, expires_at)` backs the sweep's third
 *   range scan (`is_terminal = false AND on_timeout IS NOT NULL AND
 *   expires_at <= now ORDER BY expires_at LIMIT batch`), keeping the
 *   bounded-scan discipline flow-business-timers design D-8 requires.
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
 * @spec openspec/changes/task-expiry-and-outcomes/specs/task-expiry-and-outcomes/spec.md#requirement-a-task-declares-its-timeout-and-reject-behaviour-in-one-vocabulary
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the declared-behaviour columns and the expiry-scan index.
 *
 * @spec openspec/changes/task-expiry-and-outcomes/specs/task-expiry-and-outcomes/spec.md#requirement-the-timer-sweep-enforces-a-declared-task-expiry
 */
class Version1Date20260902090000 extends SimpleMigrationStep {

	/**
	 * The task table.
	 */
	private const TABLE_TASKS = 'openregister_tasks';

	/**
	 * Add the columns and the index, idempotently.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/task-expiry-and-outcomes/specs/task-expiry-and-outcomes/spec.md#requirement-a-task-declares-its-timeout-and-reject-behaviour-in-one-vocabulary
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE_TASKS) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE_TASKS);
		$changed = false;

		if ($table->hasColumn('on_timeout') === false) {
			$table->addColumn('on_timeout', Types::STRING, ['notnull' => false, 'length' => 32]);
			$changed = true;
		}

		if ($table->hasColumn('on_reject') === false) {
			$table->addColumn('on_reject', Types::STRING, ['notnull' => false, 'length' => 32]);
			$changed = true;
		}

		if ($table->hasIndex('or_tasks_open_expiry') === false) {
			$table->addIndex(['is_terminal', 'expires_at'], 'or_tasks_open_expiry');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
