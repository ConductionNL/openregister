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

class Version1Date20241020231700 extends SimpleMigrationStep {

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

		// create the openregister_logs table		
		if (!$schema->hasTable('openregister_logs')) {
			$table = $schema->createTable('openregister_logs');
			$table->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);	
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('object', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('changed', Types::JSON, ['notnull' => true]);
			$table->addColumn('user', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('user_name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('session', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('request', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true]);
			
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user'], 'openregister_logs_user_index');
			$table->addIndex(['uuid'], 'openregister_logs_uuid_index');
		}

		///Update the openregister_objects table
		$table = $schema->getTable('openregister_objects');
		if (!$table->hasColumn('text_representation')) {
			$table->addColumn(name: 'text_representation', typeName: Types::TEXT, options: ['notnull' => false]);
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
