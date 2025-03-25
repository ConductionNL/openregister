<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add locked, owner, authorization and folder columns to openregister_objects table
 * and folder column to openregister_registers table.
 * These columns are used to track object locking, ownership, access permissions and folder location
 */
class Version1Date20250321061615 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Update the openregister_schemas table
		$table = $schema->getTable('openregister_objects');
		
		// Add organisation column to store organisation name
		if ($table->hasColumn('organisation') === false) {
			$table->addColumn('organisation', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		// Add application column to store application name
		if ($table->hasColumn('application') === false) {
			$table->addColumn('application', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		// Add validation column to store validation rules in JSON format
		if ($table->hasColumn('validation') === false) {
			$table->addColumn('validation', Types::JSON, [
				'notnull' => false,
			]);
		}
		// Add validation column to store validation rules in JSON format
		if ($table->hasColumn('deleted') === false) {
			$table->addColumn('deleted', Types::JSON, [
				'notnull' => false,
			]);
		}

		// Add geo column to store geo data in JSON format
		if ($table->hasColumn('geo') === false) {
			$table->addColumn('geo', Types::JSON, [
				'notnull' => false,
			]);
		}

		// Add retention column to store retention data in JSON format
		if ($table->hasColumn('retention') === false) {
			$table->addColumn('retention', Types::JSON, [
				'notnull' => false,
			]);
		}
		

		// Update the openregister_schemas table
		$table = $schema->getTable('openregister_schemas');
		
		// Add slug column to store unique identifier for objects
		if ($table->hasColumn('slug') === false) {
			$table->addColumn('slug', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		// Update the openregister_registers table
		$table = $schema->getTable('openregister_registers');

		// Add slug column to store unique identifier for registers
		if ($table->hasColumn('slug') === false) {
			$table->addColumn('slug', Types::STRING, [
				'notnull' => false,
				'length' => 255,
			]);
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
