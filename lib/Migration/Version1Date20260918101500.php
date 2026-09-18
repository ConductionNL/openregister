<?php

/**
 * The engine task carries a kind.
 *
 * Adds `kind` to `openregister_tasks`: a short free label saying what sort of
 * work a task is, as the creator named it. The engine attaches no behaviour to
 * any value; what the column buys is an inbox that can be asked for one kind
 * without reading every row.
 *
 * WHY A COLUMN AND NOT `metadata`. `metadata` is documented on the entity as
 * carried and never interpreted: no lifecycle, authorization or inbox rule may
 * read it. A filter over it would be exactly the interpretation that rule
 * refuses, and it is JSON, so it could not be indexed either.
 *
 * Idempotent: the column and its index are added only when absent.
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
 * @spec openspec/changes/the-engine-task-carries-a-kind/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the task's kind column and the index the inbox filter reads.
 *
 * @spec openspec/changes/the-engine-task-carries-a-kind/specs/flow-tasks/spec.md
 */
class Version1Date20260918101500 extends SimpleMigrationStep {
	/**
	 * The engine's task table.
	 *
	 * @var string
	 */
	private const TABLE_TASKS = 'openregister_tasks';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not.
	 *
	 * @spec openspec/changes/the-engine-task-carries-a-kind/specs/flow-tasks/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// Hands the schema back even with nothing to do: a null return drops
		// the shared snapshot and makes the next migration re-introspect the
		// whole database. Inherited fix, one line — see
		// `SchemaReuseHygieneTest`, which this file was failing.
		if ($schema->hasTable(tableName: self::TABLE_TASKS) === false) {
			return $schema;
		}

		$table = $schema->getTable(tableName: self::TABLE_TASKS);

		if ($table->hasColumn('kind') === false) {
			// 64 rather than 255: a kind is a label somebody types into a
			// manifest, not a sentence. Nullable, and null is the ordinary
			// case: it means "work", not "unknown".
			$table->addColumn('kind', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		if ($table->hasIndex('or_tasks_kind') === false) {
			// Paired with is_terminal because every kind question the inbox
			// asks is about OPEN work of that kind: "the reminders still
			// standing", never "every reminder that ever existed".
			$table->addIndex(['kind', 'is_terminal'], 'or_tasks_kind');
		}

		return $schema;
	}//end changeSchema()
}//end class
