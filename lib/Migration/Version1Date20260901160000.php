<?php

/**
 * The portal delivery request table (flow-portal-task).
 *
 * One row per (task, channel, request): the queryable delivery state of an
 * ask that left the instance through the portal seam. Additive only; nothing
 * on the task table changes, because the `external` performer type is an
 * append to a vocabulary, not a column.
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
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `openregister_portal_deliveries`.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */
class Version1Date20260901160000 extends SimpleMigrationStep {

	/**
	 * The table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_portal_deliveries';

	/**
	 * Create the table when it does not exist yet.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema instanceof ISchemaWrapper === false || $schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('task_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('party_reference', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('message', Types::JSON, ['notnull' => false]);
		$table->addColumn('error', Types::TEXT, ['notnull' => false]);
		$table->addColumn('requested_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('delivered_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		// Names stay under 30 characters, the Oracle/PostgreSQL identifier cap.
		$table->addUniqueIndex(['uuid'], 'or_portaldeliv_uuid_idx');
		$table->addIndex(['task_uuid'], 'or_portaldeliv_task_idx');
		$table->addIndex(['state', 'requested_at'], 'or_portaldeliv_state_idx');
		$table->addIndex(['party_reference'], 'or_portaldeliv_party_idx');

		return $schema;
	}//end changeSchema()
}//end class
