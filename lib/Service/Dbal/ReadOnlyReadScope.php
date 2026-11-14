<?php

/**
 * ReadOnlyReadScope — runs a read of a query-backed schema inside a read-only
 * transaction with a statement timeout, and always rolls it back.
 *
 * The statement guard ({@see ReadOnlyQueryGuard}) is a check on the text; this
 * scope is the defence the database enforces (design D-2): a statement the
 * guard missed still cannot change data, and a slow statement is stopped.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Throwable;

/**
 * Wraps one read in a read-only, time-limited transaction (design D-2).
 *
 * Per platform:
 * - PostgreSQL: `START TRANSACTION READ ONLY` and `SET LOCAL statement_timeout`;
 * - MariaDB: `SET SESSION max_statement_time` (seconds) and `START TRANSACTION READ ONLY`;
 * - MySQL: `SET SESSION max_execution_time` (milliseconds) and `START TRANSACTION READ ONLY`;
 * - Oracle: `SET TRANSACTION READ ONLY`, no statement timeout;
 * - SQLite and others: a plain transaction that is rolled back; the guard
 *   and a read-only connection user are the defence there.
 *
 * A session timeout set on MariaDB or MySQL is put back to 0 (no limit)
 * after the read, so the pooled connection is not left limited.
 *
 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
 */
class ReadOnlyReadScope {

	/**
	 * The statement timeout applied when none is given, in milliseconds.
	 *
	 * @var integer
	 */
	public const DEFAULT_TIMEOUT_MS = 30000;

	/**
	 * The platform name the guard uses for a connection.
	 *
	 * @param Connection $connection The connection.
	 *
	 * @return string `postgresql`, `mysql`, `oracle`, `sqlite` or `other`.
	 *
	 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
	 */
	public static function platformName(Connection $connection): string {
		$platform = $connection->getDatabasePlatform();

		return match (true) {
			$platform instanceof PostgreSQLPlatform => 'postgresql',
			$platform instanceof AbstractMySQLPlatform => 'mysql',
			$platform instanceof OraclePlatform => 'oracle',
			$platform instanceof SqlitePlatform => 'sqlite',
			default => 'other',
		};
	}//end platformName()

	/**
	 * Run a read in a read-only transaction with a statement timeout, then roll back.
	 *
	 * @param Connection $connection The connection the read uses.
	 * @param callable   $read       The read; called with no arguments, its result is returned.
	 * @param integer    $timeoutMs  The statement timeout in milliseconds; at least 1.
	 *
	 * @return mixed What the read returned.
	 *
	 * @throws Throwable What the read, or opening the transaction, threw; the transaction is rolled back first.
	 *
	 * @spec openspec/changes/dbal-query-schema-guarded-and-previewed/specs/dbal-virtual-registers/spec.md
	 */
	public function run(Connection $connection, callable $read, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): mixed {
		$timeoutMs = max(1, $timeoutMs);
		$platform = $connection->getDatabasePlatform();
		$session = $this->sessionTimeout(platform: $platform, timeoutMs: $timeoutMs);

		if ($session !== null) {
			$connection->executeStatement($session['set']);
		}

		$opened = false;
		try {
			foreach ($this->openStatements(platform: $platform, timeoutMs: $timeoutMs) as $statement) {
				$connection->executeStatement($statement);
				$opened = true;
			}

			if ($opened === false) {
				$connection->beginTransaction();
				$opened = true;
			}

			return $read();
		} finally {
			$this->close(connection: $connection, opened: $opened);
			if ($session !== null) {
				$connection->executeStatement($session['reset']);
			}
		}//end try
	}//end run()

	/**
	 * The statements that open a read-only transaction with a timeout.
	 *
	 * Empty when the platform opens a plain transaction through DBAL.
	 *
	 * @param object  $platform  The DBAL platform.
	 * @param integer $timeoutMs The statement timeout in milliseconds.
	 *
	 * @return array<int, string> The statements, in order.
	 */
	private function openStatements(object $platform, int $timeoutMs): array {
		return match (true) {
			$platform instanceof PostgreSQLPlatform => [
				'START TRANSACTION READ ONLY',
				'SET LOCAL statement_timeout = ' . $timeoutMs,
			],
			$platform instanceof AbstractMySQLPlatform => ['START TRANSACTION READ ONLY'],
			$platform instanceof OraclePlatform => ['SET TRANSACTION READ ONLY'],
			default => [],
		};
	}//end openStatements()

	/**
	 * The session statements that set and reset a MariaDB or MySQL statement timeout.
	 *
	 * @param object  $platform  The DBAL platform.
	 * @param integer $timeoutMs The statement timeout in milliseconds.
	 *
	 * @return array{set: string, reset: string}|null The statements, or null on other platforms.
	 */
	private function sessionTimeout(object $platform, int $timeoutMs): ?array {
		if ($platform instanceof MariaDBPlatform) {
			return [
				'set' => 'SET SESSION max_statement_time = ' . sprintf('%.3F', $timeoutMs / 1000),
				'reset' => 'SET SESSION max_statement_time = 0',
			];
		}

		if ($platform instanceof AbstractMySQLPlatform) {
			return [
				'set' => 'SET SESSION max_execution_time = ' . $timeoutMs,
				'reset' => 'SET SESSION max_execution_time = 0',
			];
		}

		return null;
	}//end sessionTimeout()

	/**
	 * Roll the read's transaction back.
	 *
	 * A read changes nothing worth keeping, and rolling back ends the
	 * read-only transaction on every platform.
	 *
	 * @param Connection $connection The connection.
	 * @param boolean    $opened     Whether a transaction was opened.
	 *
	 * @return void
	 */
	private function close(Connection $connection, bool $opened): void {
		if ($opened === false) {
			return;
		}

		if ($connection->isTransactionActive() === true) {
			$connection->rollBack();
			return;
		}

		$connection->executeStatement('ROLLBACK');
	}//end close()
}//end class
