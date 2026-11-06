<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The import-preview row column `row_number` becomes `row_no`.
 *
 * `row_number` is a reserved word on MariaDB, and Doctrine does not quote it
 * there, so Version1Date20260915050000 failed on every MariaDB install (#4909).
 * That migration now creates `row_no`; this one renames the column on the
 * installs that already have `row_number`: PostgreSQL, SQLite and MySQL 8,
 * where the old migration ran, and MariaDB installs whose tables were created
 * by hand as a workaround. The rows and the unique index stay.
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Renames `row_number` to `row_no` in the import-preview rows table.
 *
 * The rename is one RENAME COLUMN statement with quoted identifiers, not a
 * change to the Doctrine schema: Doctrine's schema comparison sees a dropped
 * and an added column, which loses the row numbers, and it writes the old
 * name unquoted, which fails on MariaDB for the same reason as the original
 * migration.
 * RENAME COLUMN keeps the data and the unique index, and every supported
 * engine has it (MariaDB 10.5.2, MySQL 8.0, PostgreSQL, SQLite 3.25).
 *
 * A fresh install never reaches this step with the old name: Nextcloud then
 * applies only changeSchema(), and Version1Date20260915050000 already
 * creates `row_no`.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class Version1Date20261106120000 extends SimpleMigrationStep {

	/**
	 * The import-preview rows table.
	 */
	public const TABLE = 'openregister_import_preview_rows';

	/**
	 * The column name that is reserved on MariaDB.
	 */
	public const OLD_COLUMN = 'row_number';

	/**
	 * The column name that replaces it.
	 */
	public const NEW_COLUMN = 'row_no';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Rename the column when the table still has the old name.
	 *
	 * Runs before changeSchema(), so the schema Nextcloud compares against
	 * already has the new name.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::OLD_COLUMN) === false) {
			return;
		}

		if ($table->hasColumn(self::NEW_COLUMN) === true) {
			$output->warning(
				'The table '.self::TABLE.' has both '.self::OLD_COLUMN.' and '.self::NEW_COLUMN
				.', so nothing is renamed. Import previews read '.self::NEW_COLUMN.'.'
			);
			return;
		}

		// Backticks: Nextcloud turns them into the quoting of the engine
		// (double quotes on PostgreSQL and SQLite) and *PREFIX* into the
		// table prefix.
		$this->db->executeStatement(
			'ALTER TABLE `*PREFIX*'.self::TABLE.'` RENAME COLUMN `'.self::OLD_COLUMN.'` TO `'.self::NEW_COLUMN.'`'
		);
		$output->info('Renamed '.self::TABLE.'.'.self::OLD_COLUMN.' to '.self::NEW_COLUMN);
	}//end preSchemaChange()

	/**
	 * Nothing to change in the schema: the rename happens in preSchemaChange().
	 *
	 * Hands the schema back instead of null, so a schema-only run keeps one
	 * snapshot (see SchemaReuseHygieneTest, #3776).
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, unchanged.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		return $schema;
	}//end changeSchema()
}//end class
