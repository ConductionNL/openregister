<?php

/**
 * Named draft versions of an object — the draft store.
 *
 * `openregister_object_drafts` holds one row per named draft of an object: the
 * object's uuid, register and schema, the draft key (unique per object, never
 * `main`), a name, its creator, the object version it was taken against, and the
 * DELTA of changed fields as json. The live object in its magic table is not
 * touched until a draft is promoted, and because drafts live in their own table
 * no list, count, facet, search or relation query over objects can return one.
 *
 * The key column is `draft_key`, not `key`, and the object's register and schema
 * are `object_register` and `object_schema`: `KEY` and `SCHEMA` are reserved words
 * on some of the supported databases.
 *
 * Idempotent: the table is created only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/records-draft-versions/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the object draft store.
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
class Version1Date20261113140000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_object_drafts') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_object_drafts');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('object_register', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('object_schema', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('draft_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('base_version', Types::STRING, ['notnull' => false, 'length' => 32]);
		$table->addColumn('delta', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['uuid'], 'idx_or_objdraft_uuid');
		$table->addUniqueIndex(['object_uuid', 'draft_key'], 'idx_or_objdraft_key');

		$output->info('Created openregister_object_drafts table');

		return $schema;

	}//end changeSchema()
}//end class
