<?php

/**
 * Create `openregister_upload_sessions` for uploads in parts.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * The upload sessions table: one row per upload in parts (REQ-LFH-002).
 *
 * Idempotent: created only when absent.
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
 */
class Version1Date20261114180006 extends TargetedMigrationStep {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_upload_sessions';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === true) {
			return $schema;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('upload_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('sha256', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('part_size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('received', Types::TEXT, ['notnull' => false]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['upload_id'], 'or_upload_sess_id_uidx');
		$table->addIndex(['expires_at'], 'or_upload_sess_exp_idx');
		$output->info('Created ' . self::TABLE . ' for uploads in parts.');

		return $schema;
	}//end changeTargetedSchema()
}//end class
