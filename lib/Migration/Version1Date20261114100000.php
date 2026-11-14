<?php

/**
 * The irreversibility verdict of an anonymisation run.
 *
 * `openregister_anonymisation_log.verification` holds, per run, the verdict
 * the verifier took on the written copy as JSON: verdict, output mode, the
 * routes checked and a count per route, never a redacted value.
 *
 * Idempotent: the column is added only when absent.
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
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the verdict column to the anonymisation log.
 */
class Version1Date20261114100000 extends SimpleMigrationStep {

	/**
	 * Add `verification` to the anonymisation log.
	 *
	 * @param IOutput $output        The migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_anonymisation_log') === true) {
			$log = $schema->getTable('openregister_anonymisation_log');
			if ($log->hasColumn('verification') === false) {
				$log->addColumn('verification', Types::TEXT, ['notnull' => false]);
				$output->info('Added verification to openregister_anonymisation_log');
			}
		}

		return $schema;

	}//end changeSchema()
}//end class
