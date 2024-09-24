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
class Version1Date20240924200009 extends SimpleMigrationStep {

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

		if (!$schema->hasTable('openregister_sources')) {
			$table = $schema->createTable('openregister_sources');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('database_url', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['title'], 'register_sources_title_index');
			$table->addIndex(['type'], 'register_sources_type_index');
		}

		if (!$schema->hasTable('openregister_schemas')) {
			$table = $schema->createTable('openregister_schemas');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$table->addColumn('required', Types::JSON, ['notnull' => false]);
			$table->addColumn('properties', Types::JSON, ['notnull' => false]);
			$table->addColumn('archive', Types::JSON, ['notnull' => false]);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['title'], 'register_schemas_title_index');
			$table->addIndex(['source'], 'register_schemas_source_index');
		}

		if (!$schema->hasTable('openregister_registers')) {
			$table = $schema->createTable('openregister_registers');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('schemas', Types::JSON, ['notnull' => false]);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('table_prefix', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['title'], 'registers_title_index');
			$table->addIndex(['source'], 'registers_source_index');
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
