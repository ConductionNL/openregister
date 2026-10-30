<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A task's external key is unique: a unique index on `openregister_tasks.task_key`.
 * NULL keys stay allowed any number of times. A caller that adds a task once per
 * thing keys it, and the database refuses the second insert of two concurrent
 * ones instead of storing both.
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
 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the unique index on the task key, unless stored keys already collide.
 *
 * An instance whose tasks already share a key cannot take the index; the
 * upgrade then says so, names the count and leaves the table as it was, rather
 * than failing the upgrade or rewriting somebody's keys. Nextcloud never runs
 * this migration again, so the post-migration repair step
 * EnsureTaskKeyUniqueIndex adds the index later, once the keys are distinct.
 *
 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
 */
class Version1Date20261024120000 extends SimpleMigrationStep {

	/**
	 * The tasks table.
	 */
	public const TABLE = 'openregister_tasks';

	/**
	 * The index name.
	 */
	public const INDEX = 'or_tasks_key_uniq';

	/**
	 * How many keys are shared by more than one task, measured before the change.
	 *
	 * @var integer
	 */
	private int $collisions = 0;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Count the keys that more than one task carries.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($schemaClosure()->hasTable(self::TABLE) === false) {
			return;
		}

		$this->collisions = $this->sharedKeys();
		if ($this->collisions > 0) {
			$output->warning(
				$this->collisions . ' task keys are shared by more than one task, so task_key is not made unique. '
				. 'Give those tasks distinct keys, then run occ maintenance:repair to add the index.'
			);
		}
	}//end preSchemaChange()

	/**
	 * Add the unique index when it is absent and the keys allow it.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false || $this->collisions > 0) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('task_key') === false || $table->hasIndex(self::INDEX) === true) {
			return $schema;
		}

		$table->addUniqueIndex(['task_key'], self::INDEX);

		return $schema;
	}//end changeSchema()

	/**
	 * The number of non-null keys carried by more than one task.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/task-key-unique/specs/flow-tasks/spec.md
	 */
	public function sharedKeys(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('task_key')
			->from(self::TABLE)
			->where($qb->expr()->isNotNull('task_key'))
			->groupBy('task_key')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1)));

		$result = $qb->executeQuery();
		$count = count($result->fetchAll());
		$result->closeCursor();

		return $count;
	}//end sharedKeys()
}//end class
