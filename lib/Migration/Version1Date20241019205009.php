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

class Version1Date20241019205009 extends SimpleMigrationStep {

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

		// Update the openregister_sources table
		$table = $schema->getTable('openregister_sources');
		if (!$table->hasColumn('uuid')) {
			$table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
			$table->addIndex(['uuid'], 'openregister_sources_uuid_index');
		}
		if (!$table->hasColumn('version')) {
			$table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
		}

		// Update the openregister_schemas table
		$table = $schema->getTable('openregister_schemas');
		if (!$table->hasColumn('uuid')) {
			$table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
			$table->addIndex(['uuid'], 'openregister_schemas_uuid_index');
		}
			
		// Update the openregister_registers table
		$table = $schema->getTable('openregister_registers');
		if (!$table->hasColumn('uuid')) {
			$table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
			$table->addIndex(['uuid'], 'openregister_registers_uuid_index');
		}
		if (!$table->hasColumn('version')) {
			$table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
		}

		// Update the openregister_objects table
		$table = $schema->getTable('openregister_objects');
		if (!$table->hasColumn('version')) {
			$table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
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
