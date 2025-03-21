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

		// Update the openregister_objects table
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
