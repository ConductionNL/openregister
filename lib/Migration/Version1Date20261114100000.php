<?php

/**
 * OpenRegister Migration Version1Date20261114100000
 *
 * Adds `openregister_foreign_ids`: the identifiers other systems gave an
 * object, each naming the system that issued it. One value per issuer is
 * unique, so a lookup by issuer and value finds exactly one object while two
 * issuers may use the same value. Purely additive; created only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the `openregister_foreign_ids` table.
 */
class Version1Date20261114100000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null when no change.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_foreign_ids') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_foreign_ids');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('issuer', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('value', Types::STRING, ['notnull' => true, 'length' => 191]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['issuer', 'value'], 'idx_or_fid_issuer_value');
		$table->addIndex(['object_uuid'], 'idx_or_fid_object');

		$output->info('Created openregister_foreign_ids table');

		return $schema;
	}//end changeSchema()
}//end class
