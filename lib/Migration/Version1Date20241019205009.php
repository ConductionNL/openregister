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
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
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

		// Update the abilities table to add the base column
		$table = $schema->getTable('openregister_sources');
		$table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
		$table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
		$table->addIndex(['uuid'], 'openregister_sources_uuid_index');

		// Update the abilities table to add the base column
		$table = $schema->getTable('openregister_schemas');
		$table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
		$table->addIndex(['uuid'], 'openregister_sources_uuid_index');
			
		// Update the abilities table to add the base column
		$table = $schema->getTable('openregister_registers');
		$table->addColumn(name: 'uuid', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255]);
		$table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
		$table->addIndex(['uuid'], 'openregister_sources_uuid_index');

		// Update the abilities table to add the base column
		$table = $schema->getTable('openregister_objects');
		$table->addColumn(name: 'version', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);

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
