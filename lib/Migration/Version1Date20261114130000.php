<?php

/**
 * Create the data quality audit tables: the audits, their kept runs and the failing records.
 *
 * - `openregister_quality_audits`: a named population (register, schema,
 *   filters), a rule set, a tolerance and an optional schedule (REQ-RCN-003).
 * - `openregister_quality_audit_runs`: one row per run, with the tolerance AS
 *   IT STOOD, so a later change to the audit does not re-judge it (D-4).
 * - `openregister_quality_audit_failures`: the records that failed a run and
 *   the rules they failed, read a page at a time (ADR-009).
 *
 * Idempotent: every table is created only while it is missing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the three quality audit tables.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */
class Version1Date20261114130000 extends SimpleMigrationStep {

	/**
	 * Create the tables.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper The schema, changed or not: a null return drops the shared snapshot.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md#requirement-a-quality-audit-run-is-kept-with-its-failures-and-the-tolerance-it-was-judged-against-req-rcn-004
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('openregister_quality_audits') === false) {
			$table = $schema->createTable('openregister_quality_audits');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('register_ref', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('schema_ref', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('filters', Types::TEXT, ['notnull' => false]);
			$table->addColumn('rules', Types::TEXT, ['notnull' => false]);
			$table->addColumn('tolerance', Types::FLOAT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('schedule', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('last_run_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'idx_or_qaudit_uuid');
			$table->addIndex(['schedule'], 'idx_or_qaudit_schedule');
			$changed = true;
		}

		if ($schema->hasTable('openregister_quality_audit_runs') === false) {
			$table = $schema->createTable('openregister_quality_audit_runs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('audit_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('started_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('started_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('evaluated', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
			$table->addColumn('score', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('tolerance', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('verdict', Types::STRING, ['notnull' => false, 'length' => 8]);
			$table->addColumn('failed', Types::INTEGER, ['notnull' => true, 'default' => 0, 'unsigned' => true]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uuid'], 'idx_or_qarun_uuid');
			$table->addIndex(['audit_id', 'started_at'], 'idx_or_qarun_audit');
			$changed = true;
		}

		if ($schema->hasTable('openregister_quality_audit_failures') === false) {
			$table = $schema->createTable('openregister_quality_audit_failures');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('record', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('rules', Types::TEXT, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'idx_or_qafail_run');
			$changed = true;
		}

		if ($changed === true) {
			$output->info('Created the quality audit tables');
		}

		return $schema;
	}//end changeSchema()
}//end class
