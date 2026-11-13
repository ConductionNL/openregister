<?php

/**
 * Add the mandate columns to the task and task-audit tables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-human-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * tasks-delegation-and-substitution 1.1: `required_mandate` and
 * `mandate_evidence` on `openregister_tasks`, and `mandate_evidence` on
 * `openregister_task_audit`. All nullable, so existing rows are unaffected.
 */
class Version1Date20261113120000 extends SimpleMigrationStep {

	public const TASKS = 'openregister_tasks';

	public const AUDIT = 'openregister_task_audit';

	/**
	 * Add the nullable columns when their tables and columns are not already there.
	 *
	 * @param IOutput  $output        The migration output.
	 * @param Closure  $schemaClosure The schema accessor.
	 * @param array    $options       The migration options.
	 *
	 * @return ISchemaWrapper The schema, handed back so the shared snapshot is kept.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-human-tasks/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TASKS) === true) {
			$tasks = $schema->getTable(self::TASKS);
			if ($tasks->hasColumn('required_mandate') === false) {
				$tasks->addColumn('required_mandate', Types::STRING, ['notnull' => false, 'length' => 128]);
			}

			if ($tasks->hasColumn('mandate_evidence') === false) {
				$tasks->addColumn('mandate_evidence', Types::JSON, ['notnull' => false]);
			}
		}

		if ($schema->hasTable(self::AUDIT) === true) {
			$audit = $schema->getTable(self::AUDIT);
			if ($audit->hasColumn('mandate_evidence') === false) {
				$audit->addColumn('mandate_evidence', Types::JSON, ['notnull' => false]);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
