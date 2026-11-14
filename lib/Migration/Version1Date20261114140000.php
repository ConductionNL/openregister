<?php

/**
 * OpenRegister Migration Version1Date20261114140000
 *
 * Harvest columns for apps (app-harvest-fetchers-and-flow-node): the owning app, fetcher config,
 * acting identity, schedule, harvest flow and item rules on openregister_sources; the conflict reason,
 * tombstone, last applied time and resolution history on openregister_sync_records. Every column is
 * nullable, so an existing source behaves as before.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the harvest-for-apps columns.
 */
class Version1Date20261114140000 extends SimpleMigrationStep {

	/**
	 * New columns per table: name, type, options.
	 *
	 * @var array<string, list<array{0: string, 1: string, 2: array<string, mixed>}>>
	 */
	private const COLUMNS = [
		'openregister_sources' => [
			['application', Types::STRING, ['notnull' => false, 'length' => 64]],
			['config', Types::TEXT, ['notnull' => false]],
			['run_as', Types::STRING, ['notnull' => false, 'length' => 64]],
			['schedule', Types::STRING, ['notnull' => false, 'length' => 64]],
			['flow_id', Types::STRING, ['notnull' => false, 'length' => 36]],
			['identity_property', Types::STRING, ['notnull' => false, 'length' => 255]],
			['protected_fields', Types::TEXT, ['notnull' => false]],
			['provenance', Types::TEXT, ['notnull' => false]],
			['conflict_rules', Types::TEXT, ['notnull' => false]],
			['max_items_per_run', Types::INTEGER, ['notnull' => false, 'unsigned' => true]],
		],
		'openregister_sync_records' => [
			['conflict_reason', Types::STRING, ['notnull' => false, 'length' => 32]],
			['tombstoned', Types::BOOLEAN, ['notnull' => false, 'default' => false]],
			['tombstoned_at', Types::DATETIME, ['notnull' => false]],
			['last_applied_at', Types::DATETIME, ['notnull' => false]],
			['resolutions', Types::TEXT, ['notnull' => false]],
		],
	];

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process
	 * @param Closure                 $schemaClosure The schema closure
	 * @param array<array-key, mixed> $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		foreach (self::COLUMNS as $tableName => $columns) {
			if ($schema->hasTable($tableName) === false) {
				continue;
			}

			$table = $schema->getTable($tableName);
			foreach ($columns as [$columnName, $columnType, $columnOptions]) {
				if ($table->hasColumn($columnName) === false) {
					$table->addColumn($columnName, $columnType, $columnOptions);
				}
			}

			$output->info('Harvest-for-apps columns present on ' . $tableName);
		}

		$this->addIndexes(schema: $schema);

		return $schema;
	}//end changeSchema()

	/**
	 * Index the flow id (the job skips sources that have one) and the tombstone flag.
	 *
	 * @param ISchemaWrapper $schema The schema.
	 *
	 * @return void
	 */
	private function addIndexes(ISchemaWrapper $schema): void {
		if ($schema->hasTable('openregister_sources') === true) {
			$table = $schema->getTable('openregister_sources');
			if ($table->hasIndex('sources_flow_id_idx') === false) {
				$table->addIndex(['flow_id'], 'sources_flow_id_idx');
			}
		}

		if ($schema->hasTable('openregister_sync_records') === true) {
			$table = $schema->getTable('openregister_sync_records');
			if ($table->hasIndex('sync_rec_tombstoned_idx') === false) {
				$table->addIndex(['source_id', 'tombstoned'], 'sync_rec_tombstoned_idx');
			}
		}
	}//end addIndexes()
}//end class
