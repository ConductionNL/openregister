<?php

/**
 * The run-held object lock registry.
 *
 * Additive: one new table, no column added to and no data changed on any
 * existing one. The lock payload itself gains its `kind` and `runUuid` keys
 * inside the existing `_locked` JSON column and so needs no schema change and
 * no back-fill, which is the whole reason the holder was modelled there. A
 * record written before this migration carries no `kind` and reads as the
 * user lock it is.
 *
 * Reverting the app code leaves this table inert rather than broken. Dropping
 * it is a separate, optional migration and MUST NOT be part of the rollback.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_run_object_locks`.
 */
class Version1Date20260905120000 extends SimpleMigrationStep {

	private const LOCKS = 'openregister_run_object_locks';

	/**
	 * Create the table.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The updated schema, or null when nothing changed.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(self::LOCKS) === true) {
			return null;
		}

		$table = $schema->createTable(self::LOCKS);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('run_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 40]);
		$table->addColumn('register_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addColumn('schema_id', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addColumn('node_id', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		$table->addColumn('locked_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
		$table->addColumn('expires_at', Types::DATETIME_MUTABLE, ['notnull' => false, 'default' => null]);
		$table->setPrimaryKey(['id']);
		// A run holds an object once: re-locking extends rather than stacks.
		$table->addUniqueIndex(['run_uuid', 'object_uuid'], 'or_runlock_run_obj_uq');
		// The terminal listener's read.
		$table->addIndex(['run_uuid'], 'or_runlock_run_idx');
		// The sweep's second predicate.
		$table->addIndex(['expires_at'], 'or_runlock_exp_idx');

		$output->info(message: 'Created ' . self::LOCKS);

		return $schema;
	}//end changeSchema()
}//end class
