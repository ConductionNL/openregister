<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The per-task projection state (flow-task-inbox-projections, design D-2
 * rules 6 and 8): one row per (task, surface) recording what the projector
 * rendered, where, and when. Written by the projector only. Nothing in the
 * lifecycle or authorization reads it, so a wrong row costs one redundant
 * re-render and nothing else.
 *
 * Deliberately NOT columns on `openregister_tasks`: the task row is the
 * truth and this is bookkeeping about a copy of it. Keeping them apart is
 * what keeps "reconcile the projection" from ever looking like "update the
 * task".
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
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the task projection state table.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
 */
class Version1Date20260901130000 extends SimpleMigrationStep {

	/**
	 * The projection state table.
	 */
	private const TABLE = 'openregister_task_projections';

	/**
	 * Create the table when absent.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-projection-is-idempotent-and-does-not-feed-itself
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('task_uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('surface', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('assignee', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('calendar_id', Types::BIGINT, ['notnull' => false]);
		$table->addColumn('object_uri', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('rendered_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('rendered_at', Types::DATETIME_MUTABLE, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['task_uuid', 'surface'], 'or_task_proj_task_surface');
		$table->addIndex(['calendar_id', 'object_uri'], 'or_task_proj_calendar_uri');

		return $schema;
	}//end changeSchema()
}//end class
