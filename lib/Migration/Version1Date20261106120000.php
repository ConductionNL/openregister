<?php

/**
 * Rename openregister_import_preview_rows.row_number to source_row.
 *
 * `row_number` is a reserved word that Doctrine leaves unquoted on MariaDB, so
 * the column could not be created there. Version1Date20260915050000 now creates
 * `source_row`; this step brings the installs that already hold the old column
 * in line, keeping their rows and the unique index.
 *
 * A fresh install takes Nextcloud's schema-only path and skips this step, which
 * it does not need. Idempotent: it acts only while the old column exists.
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
 * Rename the reserved row_number column of the import preview rows.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class Version1Date20261106120000 extends SimpleMigrationStep {
	private const TABLE = 'openregister_import_preview_rows';

	/**
	 * Hold the connection the rename runs on.
	 *
	 * @param IDBConnection $connection The database connection.
	 */
	public function __construct(private readonly IDBConnection $connection) {
	}//end __construct()

	/**
	 * Rename the column before Nextcloud compares the schema.
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
		if ($schema->hasTable(self::TABLE) === false || $schema->getTable(self::TABLE)->hasColumn('row_number') === false) {
			return;
		}

		$query = $this->connection->getQueryBuilder();
		$this->connection->executeStatement(
			sprintf(
				'ALTER TABLE %s RENAME COLUMN %s TO %s',
				$query->getTableName(self::TABLE),
				$query->getColumnName('row_number'),
				$query->getColumnName('source_row')
			)
		);
		$output->info('Renamed row_number to source_row on '.self::TABLE);
	}//end preSchemaChange()
}//end class
