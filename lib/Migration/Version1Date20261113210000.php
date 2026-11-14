<?php

/**
 * Audit seal backlog — a partial index over the unsealed audit rows.
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
 * @spec openspec/changes/audit-seal-backlog-repair/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Create `idx_audit_unsealed` on the audit trail's unsealed rows.
 *
 * The seal sweep finds its next row with `WHERE hash IS NULL ORDER BY id
 * LIMIT 1` and reports progress with `COUNT(*) WHERE hash IS NULL`. Neither can
 * use an index today: measured on the shared dev instance the cursor degraded
 * to a primary-key scan with a filter, 784 ms per 2,000 ids, slower the longer
 * the sealed prefix grows. A partial index holds only the unsealed rows, so it
 * shrinks toward empty as the backlog drains and the cursor gets faster.
 *
 * The predicate is the sweep's own (`hash IS NULL`), so the planner can use it
 * for exactly those queries. Partial indexes exist on PostgreSQL and SQLite
 * only; on MySQL, MariaDB and Oracle the step says so and changes nothing,
 * since a full index on `id` would only duplicate the primary key. Idempotent
 * (`IF NOT EXISTS`), and a refused statement is reported, never fatal: the
 * index is a speed-up, and the sweep is correct without it.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/tasks.md
 */
class Version1Date20261113210000 extends SimpleMigrationStep {

	/**
	 * The index name.
	 */
	public const INDEX = 'idx_audit_unsealed';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection.
	 */
	public function __construct(private readonly IDBConnection $connection) {
	}//end __construct()

	/**
	 * Create the partial index after the schema step.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/tasks.md
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$provider = $this->connection->getDatabaseProvider();
		if ($provider !== IDBConnection::PLATFORM_POSTGRES && $provider !== IDBConnection::PLATFORM_SQLITE) {
			$output->info('Skipping ' . self::INDEX . ': partial indexes exist on PostgreSQL and SQLite only.');
			return;
		}

		$table = $this->connection->getQueryBuilder()->getTableName('openregister_audit_trails');
		try {
			$this->connection->executeStatement(
				'CREATE INDEX IF NOT EXISTS ' . self::INDEX . ' ON ' . $table . ' (id) WHERE hash IS NULL'
			);
		} catch (Throwable $e) {
			$output->warning('Could not create ' . self::INDEX . ': ' . $e->getMessage());
			return;
		}

		$output->info('Created ' . self::INDEX . ' (or it was already present).');
	}//end postSchemaChange()
}//end class
