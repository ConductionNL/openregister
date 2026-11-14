<?php

/**
 * Index `openregister_flow_runs` on (flow_id, created) for the progress report.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add `or_flowrun_flow_created_idx` on openregister_flow_runs(flow_id, created).
 *
 * The progress report counts a flow's runs created in a window, grouped by
 * status (design D-1). The existing `or_flowrun_flow_idx` is (flow_id, id),
 * which narrows to the flow but then filters every one of its runs on
 * `created`; this index answers the window directly. Idempotent: added only
 * when the table exists and the index does not.
 *
 * @spec openspec/changes/tasks-progress-report/tasks.md
 */
class Version1Date20261113220000 extends SimpleMigrationStep {

	/**
	 * The index name.
	 */
	public const INDEX = 'or_flowrun_flow_created_idx';

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/changes/tasks-progress-report/tasks.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_flow_runs') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_flow_runs');
		if ($table->hasIndex(self::INDEX) === true) {
			$output->info(self::INDEX . ' already present on openregister_flow_runs; nothing to do.');
			return $schema;
		}

		$table->addIndex(['flow_id', 'created'], self::INDEX);
		$output->info('Added ' . self::INDEX . ' on openregister_flow_runs(flow_id, created) for the flow progress report.');

		return $schema;
	}//end changeSchema()
}//end class
