<?php

/**
 * Add `sample_count` and `sample_sum_ms` to `openregister_metrics` for the request metrics.
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
 * @spec openspec/changes/production-observability
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;

/**
 * Add the nullable bigint columns `sample_count` and `sample_sum_ms` to openregister_metrics.
 *
 * The request metrics are not one row per request: the APCu counters of
 * many requests are flushed into one aggregate row per label set, which
 * carries how many observations it holds and their summed duration. Rows of
 * every other metric type leave both null. Idempotent: each column is added
 * only when the table exists and the column does not.
 *
 * @spec openspec/changes/production-observability
 */
class Version1Date20261116230000 extends TargetedMigrationStep {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_metrics';

	/**
	 * The columns.
	 */
	public const COLUMNS = ['sample_count', 'sample_sum_ms'];

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @spec openspec/changes/production-observability
	 */
	protected function changeTargetedSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		foreach (self::COLUMNS as $column) {
			if ($table->hasColumn($column) === true) {
				$output->info($column . ' already present on ' . self::TABLE . '; nothing to do.');
				continue;
			}

			$table->addColumn($column, Types::BIGINT, ['notnull' => false, 'default' => null]);
			$output->info('Added ' . $column . ' on ' . self::TABLE . ' for the request metrics.');
		}

		return $schema;
	}//end changeTargetedSchema()
}//end class
