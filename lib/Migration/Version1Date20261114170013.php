<?php

/**
 * Creates the due-moment index of calendar flow triggers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * One row per calendar trigger and record: the date value it was counted
 * from, the moment it comes due, and when (and with which run) it fired.
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
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Adds `openregister_flow_moments`.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class Version1Date20261114170013 extends TargetedMigrationStep {

	/**
	 * The table.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_flow_moments';

	/**
	 * Create the table when it is missing.
	 *
	 * @param IOutput $output        Migration output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, unchanged when the table already exists.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
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
		$table->addColumn('flow_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('node_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_uuid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('date_value', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('due_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('fired_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('run_uuid', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('skipped', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['flow_uuid', 'node_id', 'object_uuid'], 'or_flowmoment_trigger_obj_uq');
		$table->addIndex(['fired_at', 'due_at'], 'or_flowmoment_due_idx');
		$table->addIndex(['object_uuid'], 'or_flowmoment_object_idx');

		$output->info('Created ' . self::TABLE . ' for calendar flow triggers.');

		return $schema;
	}//end changeTargetedSchema()
}//end class
