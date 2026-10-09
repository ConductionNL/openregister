<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An export profile declares how long the files it produces are kept.
 *
 * Adds `retention_days` to `openregister_export_profiles`. Null means the
 * profile keeps its files; a run produced under it says so (`kept`). A run
 * copies the retention it was produced under, so editing this column later
 * never moves an existing file's deadline.
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
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-export-expires-and-the-row-outlives-the-file
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the file retention column to export profiles.
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-export-expires-and-the-row-outlives-the-file
 */
class Version1Date20261009150000 extends SimpleMigrationStep {

	/**
	 * The export profiles table.
	 */
	private const TABLE = 'openregister_export_profiles';

	/**
	 * Add the column unless it is already there.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md#requirement-an-export-expires-and-the-row-outlives-the-file
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('retention_days') === true) {
			return $schema;
		}

		$table->addColumn('retention_days', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
		$output->info('Added retention_days to '.self::TABLE);

		return $schema;
	}//end changeSchema()
}//end class
