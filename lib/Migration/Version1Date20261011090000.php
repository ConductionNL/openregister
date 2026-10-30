<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Record templates (records-saved-templates D-1): a new table
 * `openregister_record_templates` beside the saved views, with the same three
 * sharing columns (owner, is_public, shared_with) plus the register, the
 * schema, a name, a description and the values a new record starts with.
 * The values column is `template_values` because VALUES is an SQL keyword.
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
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `openregister_record_templates`.
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class Version1Date20261011090000 extends SimpleMigrationStep {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_record_templates';

	/**
	 * Create the table when it is absent.
	 *
	 * Booleans are nullable because Nextcloud refuses a NOT NULL boolean.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === true) {
			return $schema;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('description', Types::TEXT, ['notnull' => false]);
		$table->addColumn('register', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('schema', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('template_values', Types::TEXT, ['notnull' => false]);
		$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('is_public', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('shared_with', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'or_rectpl_uuid');
		$table->addIndex(['register', 'schema'], 'or_rectpl_reg_schema');
		$table->addIndex(['owner'], 'or_rectpl_owner');

		return $schema;
	}//end changeSchema()
}//end class
