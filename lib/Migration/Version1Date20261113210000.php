<?php

/**
 * Timeline entries — the withdrawal of an entry.
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
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `withdrawn_by`, `withdrawn_at` and `withdrawn_reason` to
 * `openregister_timeline_entries`, so a withdrawn entry leaves the working
 * timeline and stays in the record with who withdrew it, when and why
 * (D-C29-4). Idempotent: each column is added only when absent, and the
 * schema is always handed back.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class Version1Date20261113210000 extends SimpleMigrationStep {

	/**
	 * Add the withdrawal columns.
	 *
	 * @param IOutput $output        Output for the migration.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_timeline_entries') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_timeline_entries');
		$columns = [
			'withdrawn_by' => [Types::STRING, ['notnull' => false, 'length' => 64]],
			'withdrawn_at' => [Types::DATETIME, ['notnull' => false]],
			'withdrawn_reason' => [Types::TEXT, ['notnull' => false]],
		];
		foreach ($columns as $name => [$type, $columnOptions]) {
			if ($table->hasColumn($name) === true) {
				continue;
			}

			$table->addColumn($name, $type, $columnOptions);
			$output->info('Added ' . $name . ' to openregister_timeline_entries');
		}

		return $schema;
	}//end changeSchema()
}//end class
