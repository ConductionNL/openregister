<?php

/**
 * Add the `presentation` JSON column to the openregister_views table.
 *
 * The `View` entity (lib/Db/View.php) gained a nullable `presentation`
 * property declaring how a saved view renders: `viewType`
 * (table|kanban|calendar) plus type-specific config (kanban:
 * groupByField/cardFields/columnOrder; calendar: dateField/endDateField).
 * This migration adds the backing column. The column is nullable and no
 * existing row is touched, so every pre-existing view keeps reading as
 * `viewType: table` (View::getPresentationFormatted() defaults a null
 * presentation to `table`) — this migration changes no rendering behaviour.
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
 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the nullable `presentation` JSON column to `openregister_views`.
 *
 * Idempotent: only adds the column when the table exists and the column is
 * absent, so re-running on an already-migrated database is a no-op.
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
 */
class Version1Date20260724000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema.
	 *
	 * @param IOutput $output Migration output
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper
	 * @param array $options Migration options
	 *
	 * @return ISchemaWrapper|null The updated schema, or null if no changes were needed
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_views') === false) {
			$output->info(message: 'openregister_views table does not exist, skipping...');
			return null;
		}

		$table = $schema->getTable('openregister_views');

		if ($table->hasColumn('presentation') === true) {
			$output->info(message: 'presentation column already exists on openregister_views, skipping...');
			return null;
		}

		$table->addColumn(
			'presentation',
			Types::JSON,
			[
				'notnull' => false,
				'default' => null,
				'comment' => 'Presentation config: viewType (table|kanban|calendar) + type-specific config',
			]
		);

		$output->info(message: 'Added presentation column to openregister_views table');

		return $schema;
	}//end changeSchema()
}//end class
