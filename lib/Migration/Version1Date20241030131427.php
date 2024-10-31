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
class Version1Date20241030131427 extends SimpleMigrationStep {

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
		if ($table->hasColumn('hard_validation') === false) {
			$table->addColumn(name: 'hard_validation', typeName: Types::BOOLEAN, options: ['notnull' => true])->setDefault(default: false);
		}

		if ($schema->hasTable('openregister_object_audit_logs') === false) {
			$table = $schema->createTable('openregister_object_audit_logs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('schema_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('object_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('session_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('changes', Types::JSON, ['notnull' => false]);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'object_audit_log_uuid');
			$table->addIndex(['schema_id'], 'object_audit_log_schema_id');
			$table->addIndex(['object_id'], 'object_audit_log_object_id');
			$table->addIndex(['user_id'], 'object_audit_log_user_id');
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
