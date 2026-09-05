<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drop the external workflow-engine tables.
 *
 * OpenRegister is the only home for a flow engine in this fleet (ADR-065), and
 * the external-engine layer that predated that decision has been removed. These
 * six tables have nothing left to read or write them.
 *
 * The step REPORTS the scheduled workflows it is about to destroy before it
 * drops anything. They are not recoverable state — every one measured on the
 * dev instance named `engine=openconnector`, an engine type the registry never
 * supported, and had `last_status=error` — but they do encode INTENT that
 * somebody wrote down, and that intent is worth putting in the upgrade output
 * rather than deleting in silence. Re-create them as flows.
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
 * @spec openspec/changes/retire-external-workflow-engines/specs/single-flow-engine/spec.md#requirement-the-external-engine-tables-are-removed-req-sfe-102
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Removes the tables the external engine layer owned.
 *
 * @spec openspec/changes/retire-external-workflow-engines/specs/single-flow-engine/spec.md#requirement-the-external-engine-tables-are-removed-req-sfe-102
 */
class Version1Date20260903150000 extends SimpleMigrationStep {

	/**
	 * The tables the external engine layer owned.
	 *
	 * @var array<int, string>
	 */
	private const TABLES = [
		'openregister_workflow_engines',
		'openregister_deployed_workflows',
		'openregister_scheduled_workflows',
		'openregister_workflow_executions',
		'openregister_actions',
		'openregister_action_logs',
	];

	/**
	 * Wire the database connection, used only to report before dropping.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @return void
	 */
	public function __construct(private readonly IDBConnection $db) {
	}//end __construct()

	/**
	 * Name every scheduled workflow that is about to be destroyed.
	 *
	 * Runs BEFORE the schema change, because afterwards there is nothing left to
	 * read. A schedule is a statement of intent — "submit the quarterly CBS
	 * figures" — and dropping the row without printing it makes that intent
	 * disappear with no trace in the upgrade log.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/retire-external-workflow-engines/specs/single-flow-engine/spec.md#requirement-the-external-engine-tables-are-removed-req-sfe-102
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		try {
			// Built through the query builder, not raw SQL. The first version of
			// this used backticks around the table name, which is MySQL syntax:
			// on Postgres it threw, the catch below swallowed it, and the report
			// never fired — silently destroying the very intent this method
			// exists to preserve. Measured on a Postgres instance.
			$qb = $this->db->getQueryBuilder();
			$qb->select('name', 'engine', 'interval_sec')
				->from('openregister_scheduled_workflows');
			$rows = $qb->executeQuery()->fetchAll();
		} catch (Throwable $e) {
			// No table, or it is already gone. Nothing to report.
			return;
		}

		if ($rows === []) {
			return;
		}

		$output->warning(
			message: sprintf(
				'%d scheduled workflow(s) are being removed with the external engine layer. '
				. 'Re-create them as OpenRegister flows:',
				count($rows)
			)
		);

		foreach ($rows as $row) {
			$output->warning(
				message: sprintf(
					'  %s (engine=%s, every %ds)',
					(string)($row['name'] ?? 'unnamed'),
					(string)($row['engine'] ?? 'unknown'),
					(int)($row['interval_sec'] ?? 0)
				)
			);
		}
	}//end preSchemaChange()

	/**
	 * Drop each table, idempotently.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/retire-external-workflow-engines/specs/single-flow-engine/spec.md#requirement-the-external-engine-tables-are-removed-req-sfe-102
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$dropped = [];

		foreach (self::TABLES as $table) {
			if ($schema->hasTable($table) === false) {
				continue;
			}

			$schema->dropTable($table);
			$dropped[] = $table;
		}

		if ($dropped === []) {
			return null;
		}

		$output->info(message: 'External workflow engine tables dropped: ' . implode(', ', $dropped));

		return $schema;
	}//end changeSchema()
}//end class
