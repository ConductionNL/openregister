<?php
/**
 * Scheduled report kinds — `kind` and `options` on the scheduled report table.
 *
 * A scheduled report used to be an object export of one register and schema.
 * The daily connection report is a scheduled report too, but it reads no
 * register of its own, so `register_id` becomes nullable and two columns are
 * added: `kind` (default `export`, so every existing row keeps its behaviour)
 * and `options` (JSON, nullable).
 *
 * Idempotent: each column is added or changed only when it is not already so.
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
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `kind` and `options` to scheduled reports and make `register_id` nullable.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */
class Version1Date20261113120000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_scheduled_reports') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_scheduled_reports');
		$changed = false;
		if ($table->hasColumn('kind') === false) {
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'export']);
			$changed = true;
		}

		if ($table->hasColumn('options') === false) {
			$table->addColumn('options', Types::TEXT, ['notnull' => false]);
			$changed = true;
		}

		if ($table->hasColumn('register_id') === true && $table->getColumn('register_id')->getNotnull() === true) {
			$table->getColumn('register_id')->setNotnull(false);
			$changed = true;
		}

		if ($changed === false) {
			return $schema;
		}

		$output->info('Added kind and options to openregister_scheduled_reports');
		return $schema;
	}//end changeSchema()
}//end class
