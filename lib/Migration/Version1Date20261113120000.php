<?php

/**
 * OpenRegister Migration Version1Date20261113120000
 *
 * Creates the automation run records table.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create `openregister_automation_records` (D-1).
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class Version1Date20261113120000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_automation_records') === true) {
			return $schema;
		}

		$table = $schema->createTable('openregister_automation_records');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('automation_key', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 191, 'default' => '']);
		$table->addColumn('transition', Types::STRING, ['notnull' => false, 'length' => 191]);
		$table->addColumn('schema_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('register_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('event_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('outcome', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('channels', Types::TEXT, ['notnull' => false]);
		$table->addColumn('message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('recorded_at', Types::DATETIME, ['notnull' => true]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['automation_key', 'recorded_at'], 'idx_or_autrec_key_at');
		$table->addIndex(['object_uuid'], 'idx_or_autrec_object');
		$table->addIndex(['recorded_at'], 'idx_or_autrec_at');

		$output->info('Created openregister_automation_records table');

		return $schema;
	}//end changeSchema()
}//end class
