<?php

/**
 * Add `duration_ms` to `openregister_webhook_logs` for the delivery latency histogram.
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
 * Add the nullable `duration_ms` column to openregister_webhook_logs.
 *
 * Each delivery attempt records how long its request took, which the
 * `webhook_delivery_duration_seconds` histogram aggregates. Rows written
 * before this column existed stay null and are left out of the histogram.
 * Idempotent: added only when the table exists and the column does not.
 *
 * @spec openspec/changes/production-observability
 */
class Version1Date20261114190000 extends TargetedMigrationStep {

	/**
	 * The table.
	 */
	public const TABLE = 'openregister_webhook_logs';

	/**
	 * The column.
	 */
	public const COLUMN = 'duration_ms';

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
		if ($table->hasColumn(self::COLUMN) === true) {
			$output->info(self::COLUMN . ' already present on ' . self::TABLE . '; nothing to do.');
			return $schema;
		}

		$table->addColumn(self::COLUMN, Types::INTEGER, ['notnull' => false, 'default' => null]);
		$output->info('Added ' . self::COLUMN . ' on ' . self::TABLE . ' for the delivery latency histogram.');

		return $schema;
	}//end changeTargetedSchema()
}//end class
