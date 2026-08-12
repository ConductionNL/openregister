<?php

/**
 * Tier-2 time-tracker (NC TimeManager) integration migration.
 *
 * Ensures the `openregister_timetracker_links` table exists with the full
 * Tier-2 schema:
 *
 *  - id (bigint, PK, autoincrement)
 *  - object_uuid (string 36, not null, indexed)
 *  - register_id (bigint unsigned, not null, indexed)
 *  - schema_id (bigint unsigned, nullable, indexed)
 *  - entry_type (string 16, not null) — `client` | `task` | `time`
 *  - client_id (string 64, nullable) — NC TimeManager client uuid
 *  - task_id (string 64, nullable) — NC TimeManager task uuid
 *  - time_id (string 64, nullable) — NC TimeManager time-entry uuid
 *  - name (string 255, not null) — cached entry name
 *  - duration (integer, nullable) — cached duration in seconds
 *  - billable (boolean, nullable) — cached billable flag
 *  - started_at (datetime, nullable) — cached entry start timestamp
 *  - linked_by (string 64, not null)
 *  - linked_at (datetime, not null)
 *
 * Composite unique `(object_uuid, entry_type, client_id, task_id, time_id)`
 * so the same client/task/time entry can only be linked to an object once.
 *
 * Idempotent: creates the table if missing, otherwise adds any missing
 * Tier-2 columns. Companion to the Tier-2 collectives/analytics/talk/
 * polls/email/forms/deck/flow/photos link tables; the wrapping
 * `TimeTrackerLinkService` replaces the `[or:{uuid}]` note/name marker
 * convention used by the Tier-1 `TimeProvider` with a proper persistence
 * layer so links survive entity renames.
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
 * Tier-2 time-tracker-links table — create-or-extend.
 */
class Version1Date20260525220000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process
	 * @param Closure $schemaClosure The schema closure
	 * @param array<array-key, mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('openregister_timetracker_links') === false) {
			$this->createTimeTrackerLinksTable(schema: $schema, output: $output);
			$changed = true;
		}

		if ($schema->hasTable('openregister_timetracker_links') === true
			&& $this->extendTimeTrackerLinksTable(schema: $schema, output: $output) === true
		) {
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create the openregister_timetracker_links table at the Tier-2 shape.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return void
	 */
	private function createTimeTrackerLinksTable(ISchemaWrapper $schema, IOutput $output): void {
		$table = $schema->createTable('openregister_timetracker_links');

		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('entry_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('client_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addColumn('task_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addColumn('time_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('duration', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('billable', Types::BOOLEAN, ['notnull' => false, 'default' => null]);
		$table->addColumn('started_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
		$table->addColumn('linked_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('linked_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(
			['object_uuid', 'entry_type', 'client_id', 'task_id', 'time_id'],
			'idx_tt_object_entry'
		);
		$table->addIndex(['object_uuid'], 'idx_tt_object');
		$table->addIndex(['register_id'], 'idx_tt_register');
		$table->addIndex(['schema_id'], 'idx_tt_schema');

		$output->info('Created openregister_timetracker_links table (Tier-2 schema)');
	}//end createTimeTrackerLinksTable()

	/**
	 * Add any missing Tier-2 columns to an existing time-tracker links table.
	 *
	 * @param ISchemaWrapper $schema The schema wrapper
	 * @param IOutput $output Migration output
	 *
	 * @return bool True when a column was added.
	 */
	private function extendTimeTrackerLinksTable(ISchemaWrapper $schema, IOutput $output): bool {
		$table = $schema->getTable('openregister_timetracker_links');
		$changed = false;

		if ($table->hasColumn('schema_id') === false) {
			$table->addColumn(
				'schema_id',
				Types::BIGINT,
				['notnull' => false, 'unsigned' => true, 'default' => null]
			);
			$table->addIndex(['schema_id'], 'idx_tt_schema');
			$output->info('Added schema_id column to openregister_timetracker_links');
			$changed = true;
		}

		if ($table->hasColumn('duration') === false) {
			$table->addColumn('duration', Types::INTEGER, ['notnull' => false, 'default' => null]);
			$output->info('Added duration column to openregister_timetracker_links');
			$changed = true;
		}

		if ($table->hasColumn('billable') === false) {
			$table->addColumn('billable', Types::BOOLEAN, ['notnull' => false, 'default' => null]);
			$output->info('Added billable column to openregister_timetracker_links');
			$changed = true;
		}

		if ($table->hasColumn('started_at') === false) {
			$table->addColumn('started_at', Types::DATETIME, ['notnull' => false, 'default' => null]);
			$output->info('Added started_at column to openregister_timetracker_links');
			$changed = true;
		}

		return $changed;
	}//end extendTimeTrackerLinksTable()
}//end class
