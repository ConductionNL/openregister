<?php

/**
 * Undo a bulk action — what a job remembers, and what it undoes.
 *
 * Adds the columns the inverse of a bulk job needs:
 *
 *  - openregister_bulk_jobs.reversal_window: how long, in seconds, this job
 *    stays undoable. Null means the action did not declare itself reversible,
 *    so the job never recorded a prior value and the reversal route refuses it.
 *  - openregister_bulk_jobs.reversible_until: the deadline itself, stamped
 *    provisionally at creation so the preview can name it and again when the
 *    job reaches a terminal state, because the window runs from when the job
 *    actually wrote.
 *  - openregister_bulk_jobs.reverses_job_id: the job this one undoes. A
 *    reversal is an ordinary job that names its cause (D-1).
 *  - openregister_bulk_jobs.reversed_by_job_id: the reversal, read from the
 *    original, so a job that has already been undone says so.
 *  - openregister_bulk_job_members.prior_values: the properties the action
 *    changed, with their values before the change. Only those properties,
 *    never a snapshot of the object (D-2).
 *  - openregister_bulk_job_members.applied_values: the values the job wrote,
 *    so a reversal can tell an untouched member from one somebody edited
 *    afterwards and must not overwrite (D-3).
 *
 * Idempotent: each column is added only when absent.
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
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the reversal columns to the bulk job and bulk job member tables.
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */
class Version1Date20260916121200 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output        Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$added = 0;

		if ($schema->hasTable('openregister_bulk_jobs') === true) {
			$added += $this->addJobColumns(schema: $schema);
		}

		if ($schema->hasTable('openregister_bulk_job_members') === true) {
			$added += $this->addMemberColumns(schema: $schema);
		}

		$output->info('Added '.$added.' reversal column(s) to the bulk job tables');

		return $schema;
	}//end changeSchema()

	/**
	 * Add the four reversal columns to the job table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return int How many columns were added.
	 */
	private function addJobColumns(ISchemaWrapper $schema): int {
		$table = $schema->getTable('openregister_bulk_jobs');
		$added = 0;

		$columns = [
			'reversal_window' => [Types::INTEGER, ['notnull' => false, 'unsigned' => true]],
			'reversible_until' => [Types::DATETIME, ['notnull' => false]],
			'reverses_job_id' => [Types::BIGINT, ['notnull' => false, 'unsigned' => true]],
			'reversed_by_job_id' => [Types::BIGINT, ['notnull' => false, 'unsigned' => true]],
		];

		foreach ($columns as $name => $definition) {
			if ($table->hasColumn($name) === true) {
				continue;
			}

			$table->addColumn($name, $definition[0], $definition[1]);
			$added++;
		}

		if ($table->hasIndex('idx_or_bulkjob_reverses') === false) {
			$table->addIndex(['reverses_job_id'], 'idx_or_bulkjob_reverses');
		}

		return $added;
	}//end addJobColumns()

	/**
	 * Add the two reversal columns to the member table.
	 *
	 * @param ISchemaWrapper $schema The schema being changed.
	 *
	 * @return int How many columns were added.
	 */
	private function addMemberColumns(ISchemaWrapper $schema): int {
		$table = $schema->getTable('openregister_bulk_job_members');
		$added = 0;

		foreach (['prior_values', 'applied_values'] as $name) {
			if ($table->hasColumn($name) === true) {
				continue;
			}

			$table->addColumn($name, Types::TEXT, ['notnull' => false]);
			$added++;
		}

		return $added;
	}//end addMemberColumns()
}//end class
