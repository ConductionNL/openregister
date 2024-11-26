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
class Version1Date20241126093715 extends SimpleMigrationStep {

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
		$table = $schema->getTable('openregister_schemas');
		if ($table->hasColumn('configuration') === false) {
			$table->addColumn(name: 'configuration', typeName: Types::JSON, options: ['notnull' => false])->setDefault(default: '{}');
		}

		// Update the openregister_objects table to add locked column
		$table = $schema->getTable('openregister_objects');
		if ($table->hasColumn('locked') === false) {
			$table->addColumn(name: 'locked', typeName: Types::JSON, options: ['notnull' => false])->setDefault(default: '{}');
		}

		// add the openregister_search_logs table
		if ($schema->hasTable('openregister_search_logs') === false) {
			$table = $schema->createTable('openregister_search_logs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('schema', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('register', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('filters', Types::JSON, ['notnull' => false]);
			$table->addColumn('terms', Types::JSON, ['notnull' => false]);
			$table->addColumn('result_count', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('user', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('user_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('session', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('request', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('ip_address', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'search_log_uuid');
			$table->addIndex(['schema'], 'search_log_schema');
			$table->addIndex(['register'], 'search_log_register');
			$table->addIndex(['user'], 'search_log_user');
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
