<?php

/**
 * Capacity of a detected person on entity relations.
 *
 * A PERSON relation carries the capacity suggested at detection (`capacity`,
 * `capacity_source`) and, once a reviewer confirmed it, the capacity applied,
 * who confirmed it and when (`capacity_applied`, `capacity_confirmed_by`,
 * `capacity_confirmed_at`). All are nullable: a relation that is not a person,
 * or was stored before this step, has none.
 *
 * Idempotent: each column is added only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the nullable capacity columns to openregister_entity_relations.
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
 */
class Version1Date20261114100000 extends SimpleMigrationStep {

	/**
	 * The added columns and their type and length.
	 *
	 * @var array<string, array{0: string, 1: int|null}>
	 */
	public const COLUMNS = [
		'capacity' => [Types::STRING, 32],
		'capacity_source' => [Types::STRING, 32],
		'capacity_applied' => [Types::STRING, 32],
		'capacity_confirmed_by' => [Types::STRING, 64],
		'capacity_confirmed_at' => [Types::DATETIME, null],
	];

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-1.3
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_entity_relations') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_entity_relations');
		foreach (self::COLUMNS as $column => [$type, $length]) {
			if ($table->hasColumn($column) === true) {
				continue;
			}

			$columnOptions = ['notnull' => false];
			if ($length !== null) {
				$columnOptions['length'] = $length;
			}

			$table->addColumn($column, $type, $columnOptions);
			$output->info('Added ' . $column . ' to openregister_entity_relations');
		}

		return $schema;
	}//end changeSchema()
}//end class
