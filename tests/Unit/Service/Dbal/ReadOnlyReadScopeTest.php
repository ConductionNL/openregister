<?php

/**
 * Tests for ReadOnlyReadScope (dbal-query-schema-guarded-and-previewed, design D-2).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Dbal
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

namespace OCA\OpenRegister\Tests\Unit\Service\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use OCA\OpenRegister\Service\Dbal\ReadOnlyReadScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The statements each platform gets, and a real rollback on SQLite.
 */
#[CoversClass(ReadOnlyReadScope::class)]
class ReadOnlyReadScopeTest extends TestCase {

	/**
	 * A connection double on a platform that records every statement.
	 *
	 * @param AbstractPlatform   $platform   The platform.
	 * @param array<int, string> $statements Filled with the statements run.
	 * @param bool               $active     What isTransactionActive() answers.
	 *
	 * @return Connection The double.
	 */
	private function connectionOn(AbstractPlatform $platform, array &$statements, bool $active = false): Connection {
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn($platform);
		$connection->method('isTransactionActive')->willReturn($active);
		$connection->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);

		return $connection;
	}//end connectionOn()

	/**
	 * The statements per platform, in order, around the read.
	 *
	 * @return array<string, array{AbstractPlatform, array<int, string>, string}>
	 */
	public static function platforms(): array {
		return [
			'PostgreSQL' => [
				new PostgreSQLPlatform(),
				['START TRANSACTION READ ONLY', 'SET LOCAL statement_timeout = 2500', 'READ', 'ROLLBACK'],
				'postgresql',
			],
			'MariaDB' => [
				new MariaDBPlatform(),
				['SET SESSION max_statement_time = 2.500', 'START TRANSACTION READ ONLY', 'READ', 'ROLLBACK', 'SET SESSION max_statement_time = 0'],
				'mysql',
			],
			'MySQL' => [
				new MySQLPlatform(),
				['SET SESSION max_execution_time = 2500', 'START TRANSACTION READ ONLY', 'READ', 'ROLLBACK', 'SET SESSION max_execution_time = 0'],
				'mysql',
			],
			'Oracle' => [
				new OraclePlatform(),
				['SET TRANSACTION READ ONLY', 'READ', 'ROLLBACK'],
				'oracle',
			],
		];
	}//end platforms()

	/**
	 * Each platform opens a read-only transaction with its timeout and rolls back.
	 *
	 * @param AbstractPlatform   $platform The platform.
	 * @param array<int, string> $expected The statements, with READ where the read runs.
	 * @param string             $name     The platform name the guard gets.
	 *
	 * @return void
	 */
	#[DataProvider('platforms')]
	public function testEachPlatformRunsTheReadReadOnlyAndRollsBack(AbstractPlatform $platform, array $expected, string $name): void {
		$statements = [];
		$connection = $this->connectionOn(platform: $platform, statements: $statements);

		$result = (new ReadOnlyReadScope())->run(
			connection: $connection,
			read: static function () use (&$statements): string {
				$statements[] = 'READ';
				return 'rows';
			},
			timeoutMs: 2500
		);

		$this->assertSame('rows', $result);
		$this->assertSame($expected, $statements);
		$this->assertSame($name, ReadOnlyReadScope::platformName(connection: $connection));
	}//end testEachPlatformRunsTheReadReadOnlyAndRollsBack()

	/**
	 * A failing read is rolled back and the session timeout is reset before the error goes on.
	 *
	 * @return void
	 */
	public function testAFailingReadIsRolledBackAndTheTimeoutReset(): void {
		$statements = [];
		$connection = $this->connectionOn(platform: new MariaDBPlatform(), statements: $statements);

		try {
			(new ReadOnlyReadScope())->run(
				connection: $connection,
				read: static function (): never {
					throw new RuntimeException('statement timeout');
				}
			);
			$this->fail('The read error was swallowed.');
		} catch (RuntimeException $exception) {
			$this->assertSame('statement timeout', $exception->getMessage());
		}

		$this->assertSame(
			['SET SESSION max_statement_time = 30.000', 'START TRANSACTION READ ONLY', 'ROLLBACK', 'SET SESSION max_statement_time = 0'],
			$statements
		);
	}//end testAFailingReadIsRolledBackAndTheTimeoutReset()

	/**
	 * A platform without read-only statements gets a DBAL transaction that is rolled back.
	 *
	 * @return void
	 */
	public function testOtherPlatformsGetARolledBackTransaction(): void {
		$statements = [];
		$connection = $this->connectionOn(platform: new SQLServerPlatform(), statements: $statements, active: true);
		$connection->expects($this->once())->method('beginTransaction');
		$connection->expects($this->once())->method('rollBack');

		(new ReadOnlyReadScope())->run(connection: $connection, read: static fn (): int => 1);

		$this->assertSame([], $statements);
		$this->assertSame('other', ReadOnlyReadScope::platformName(connection: $connection));
	}//end testOtherPlatformsGetARolledBackTransaction()

	/**
	 * On a real SQLite database a write inside the scope is undone.
	 *
	 * @return void
	 */
	public function testAWriteInsideTheScopeIsUndoneOnSqlite(): void {
		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
		$connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
		$this->assertInstanceOf(SqlitePlatform::class, $connection->getDatabasePlatform());

		$seen = (new ReadOnlyReadScope())->run(
			connection: $connection,
			read: static function () use ($connection): int {
				$connection->executeStatement('INSERT INTO t (id) VALUES (1)');
				return (int)$connection->fetchOne('SELECT COUNT(*) FROM t');
			}
		);

		$this->assertSame(1, $seen);
		$this->assertSame(0, (int)$connection->fetchOne('SELECT COUNT(*) FROM t'));
		$this->assertFalse($connection->isTransactionActive());
	}//end testAWriteInsideTheScopeIsUndoneOnSqlite()
}//end class
