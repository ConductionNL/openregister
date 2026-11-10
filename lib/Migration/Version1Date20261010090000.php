<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A task may name the task it waits on.
 *
 * Adds `blocked_by` to `openregister_tasks`: the uuid of the blocking task,
 * null for a task that waits on nothing. Only the declaration is stored; the
 * blocked state is derived on read from the blocker's `is_terminal`. Indexed,
 * because the release on a blocker's close looks dependants up by it.
 *
 * Idempotent: the column and its index are added only when absent.
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
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the blocker column to tasks.
 *
 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
 */
class Version1Date20261010090000 extends SimpleMigrationStep {

	/**
	 * The tasks table.
	 */
	private const TABLE = 'openregister_tasks';

	/**
	 * Add the column and its index unless they are already there.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('blocked_by') === false) {
			$table->addColumn('blocked_by', Types::STRING, ['notnull' => false, 'length' => 36]);
			$output->info('Added blocked_by to '.self::TABLE);
		}

		if ($table->hasIndex('or_tasks_blocked_by') === false) {
			$table->addIndex(['blocked_by', 'is_terminal'], 'or_tasks_blocked_by');
		}

		return $schema;
	}//end changeSchema()
}//end class
