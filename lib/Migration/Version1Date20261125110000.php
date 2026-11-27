<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * The table that records which objects are closed to new timeline entries and notes.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class Version1Date20261125110000 extends TargetedMigrationStep {

	/**
	 * Create `openregister_entry_closures`: one row per closed object.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_entry_closures') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_entry_closures');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('closed_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('closed_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 1024]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['object_uuid'], 'uniq_or_entry_closure_obj');

		$output->info('Created openregister_entry_closures table');

		return $schema;
	}//end changeTargetedSchema()
}//end class
