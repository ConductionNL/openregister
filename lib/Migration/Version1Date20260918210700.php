<?php

/**
 * The background job run log the operations console reads.
 *
 * Fifteen jobs asked to report themselves give fifteen answers and three that
 * forget, so the run is a row written by one wrapper (D-1). This table is that
 * row. It is indexed three ways because the console filters three ways: by
 * job, by outcome and by period, and a run log grows by one row per job per
 * cron tick, so a read that scans it is a read that gets slower every day
 * (ADR-009).
 *
 * Idempotent: the table is created only when absent.
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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the job run log.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
class Version1Date20260918210700 extends SimpleMigrationStep {

	/**
	 * The run log table.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_job_runs';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === true) {
			return $schema;
		}

		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('job_class', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('argument_digest', Types::STRING, ['notnull' => false, 'length' => 40]);
		$table->addColumn('started', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('ended', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('duration_ms', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('outcome', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'running']);
		$table->addColumn('message', Types::TEXT, ['notnull' => false]);
		$table->addColumn('details', Types::TEXT, ['notnull' => false]);
		$table->addColumn('cause', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'schedule']);
		$table->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);

		$table->setPrimaryKey(['id']);
		// The console's default read: the last day of runs, newest first.
		$table->addIndex(['started'], 'or_jobrun_started_idx');
		// One job's history, and the "is it running" read that refuses a
		// double start: the equality columns lead, the range column follows.
		$table->addIndex(['job_class', 'outcome', 'started'], 'or_jobrun_job_idx');
		// The failure filter and the alert threshold, across every job.
		$table->addIndex(['outcome', 'started'], 'or_jobrun_outcome_idx');

		$output->info('Created '.self::TABLE.' table');

		return $schema;

	}//end changeSchema()
}//end class
