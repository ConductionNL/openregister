<?php

/**
 * OpenRegister Migration Version1Date20261113140100
 *
 * Adds the data classification and the catalogue block to schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Nullable `classification` (a column, so a list can filter on it) and
 * `catalogue` (json) on `openregister_schemas` (D-1).
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
 */
class Version1Date20261113140100 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_schemas') === false) {
			return null;
		}

		$table   = $schema->getTable('openregister_schemas');
		$changed = false;
		if ($table->hasColumn('classification') === false) {
			$table->addColumn('classification', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addIndex(['classification'], 'idx_or_schemas_classification');
			$changed = true;
		}

		if ($table->hasColumn('catalogue') === false) {
			$table->addColumn('catalogue', Types::JSON, ['notnull' => false]);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		$output->info('Added classification and catalogue to openregister_schemas');

		return $schema;
	}//end changeSchema()
}//end class
