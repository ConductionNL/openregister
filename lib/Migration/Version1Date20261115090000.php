<?php

/**
 * Object writes under 500 ms — an expression index on the lowered register slug.
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
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Create `idx_registers_lower_slug` on `LOWER(slug)` of the register table.
 *
 * RegisterMapper matches a slug case-insensitively (`LOWER(slug) = :slug`). A
 * plain index on `slug` cannot serve that comparison, so every slug lookup was
 * a sequential scan of the register table, the same defect the schema table
 * had (Version1Date20261114170000). An expression index on the same
 * expression lets the planner use it.
 *
 * Expression indexes in this form exist on PostgreSQL and SQLite; on MySQL,
 * MariaDB and Oracle the step says so and changes nothing. Idempotent
 * (`IF NOT EXISTS`), and a refused statement is reported, never fatal: the
 * index is a speed-up, and the lookup is correct without it.
 *
 * @spec openspec/changes/object-write-sub-500ms/tasks.md
 */
class Version1Date20261115090000 extends SimpleMigrationStep {

	/**
	 * The index name.
	 */
	public const INDEX = 'idx_registers_lower_slug';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/tasks.md
	 */
	public function __construct(private readonly IDBConnection $connection) {
	}//end __construct()

	/**
	 * Create the index where the platform supports an expression index.
	 *
	 * @param IOutput $output        The migration output.
	 * @param Closure $schemaClosure The schema closure (unused).
	 * @param array   $options       The migration options (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The step signature is fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/tasks.md
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$provider = $this->connection->getDatabaseProvider();
		if ($provider !== IDBConnection::PLATFORM_POSTGRES && $provider !== IDBConnection::PLATFORM_SQLITE) {
			$output->info('Skipping ' . self::INDEX . ': this expression index is created on PostgreSQL and SQLite only.');
			return;
		}

		$table = $this->connection->getQueryBuilder()->getTableName('openregister_registers');
		try {
			$this->connection->executeStatement(
				'CREATE INDEX IF NOT EXISTS ' . self::INDEX . ' ON ' . $table . ' (LOWER(slug))'
			);
		} catch (Throwable $e) {
			$output->warning('Could not create ' . self::INDEX . ': ' . $e->getMessage());
			return;
		}

		$output->info('Created ' . self::INDEX . ' (or it was already present).');
	}//end postSchemaChange()
}//end class
