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
 * Migration to add locked, owner and authorization columns to openregister_objects table
 * These columns are used to track object locking, ownership and access permissions
 */
class Version1Date20250115230511 extends SimpleMigrationStep {

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
		$table = $schema->getTable('openregister_objects');
		
		// Add locked column to store lock tokens as JSON array
		if ($table->hasColumn('locked') === false) {
			$table->addColumn('locked', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
		}

		// Add owner column to store user ID of object owner
		if ($table->hasColumn('owner') === false) {
			$table->addColumn('owner', Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => '',
			]);
		}

		// Add authorization column to store access permissions as JSON object
		if ($table->hasColumn('authorization') === false) {
			$table->addColumn('authorization', Types::TEXT, [
				'notnull' => false,
				'default' => null,
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
