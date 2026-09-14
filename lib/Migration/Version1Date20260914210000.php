<?php

/**
 * Bulk action jobs — the job table and its per-member outcome table.
 *
 * Creates two tables backing the bulk-action-jobs capability:
 *
 *  - openregister_bulk_jobs: one row per bulk act (action, selection, actor,
 *    justification, state machine, progress counters, resumable cursor and a
 *    summary report).
 *  - openregister_bulk_job_members: the per-object outcome side table, so a
 *    job row stays small whether the selection holds four objects or four
 *    thousand, and so a retry can tell an applied member from a pending one.
 *
 * Idempotent: each table is created only when absent.
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
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the bulk job and bulk job member tables.
 *
 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
 */
class Version1Date20260914210000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Output for the migration process.
	 * @param Closure $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_bulk_jobs') === false) {
			$table = $schema->createTable('openregister_bulk_jobs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('parameters', Types::TEXT, ['notnull' => false]);
			$table->addColumn('selection_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ids']);
			$table->addColumn('selection', Types::TEXT, ['notnull' => false]);
			$table->addColumn('register_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('schema_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('justification', Types::TEXT, ['notnull' => false]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'previewed']);
			$table->addColumn('total', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('processed', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('applied', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('skipped', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('refused', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('failed', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('cursor', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('report', Types::TEXT, ['notnull' => false]);
			$table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'idx_or_bulkjob_uuid');
			$table->addIndex(['started_by', 'state'], 'idx_or_bulkjob_actor');
			$table->addIndex(['state'], 'idx_or_bulkjob_state');

			$output->info('Created openregister_bulk_jobs table');
		}//end if

		if ($schema->hasTable('openregister_bulk_job_members') === false) {
			$table = $schema->createTable('openregister_bulk_job_members');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('job_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('object_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('outcome', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
			$table->addColumn('schema_version', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('added_at_commit', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			// The idempotence key, and deliberately not the outcome column: a
			// member the PREVIEW says would apply still has to be walked by
			// the commit. Only a real write stamps this, so a retry skips
			// exactly the members that were written and no others (D-5).
			$table->addColumn('applied_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['job_id'], 'idx_or_bulkmem_job');
			$table->addIndex(['job_id', 'outcome'], 'idx_or_bulkmem_outcome');
			$table->addIndex(['job_id', 'applied_at'], 'idx_or_bulkmem_applied');
			$table->addUniqueIndex(['job_id', 'object_uuid'], 'idx_or_bulkmem_unique');

			$output->info('Created openregister_bulk_job_members table');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
