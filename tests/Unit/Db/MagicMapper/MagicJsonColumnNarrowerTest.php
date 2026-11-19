<?php

/**
 * MagicJsonColumnNarrowerTest: existing LONGTEXT JSON columns become checked MEDIUMTEXT, one ALTER per table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDb1060Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicJsonColumnNarrower;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicJsonColumnNarrower
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 */
class MagicJsonColumnNarrowerTest extends TestCase {

	private const TEXT = 'MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';

	/**
	 * Statements the connection ran.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * Catalogue queries the connection prepared, with their pattern.
	 *
	 * @var list<array{0: string, 1: array}>
	 */
	private array $queries = [];

	/**
	 * Table names whose ALTER the database refuses.
	 *
	 * @var list<string>
	 */
	private array $refuse = [];

	/**
	 * The narrower on a catalogue holding the given rows.
	 *
	 * @param array{columns?: list<array>, checks?: list<array>, tables?: list<array>} $catalogue Rows per catalogue view.
	 * @param AbstractPlatform|null                                                    $platform  The platform; MariaDB by default.
	 * @param string                                                                   $prefix    The table prefix.
	 *
	 * @return MagicJsonColumnNarrower
	 */
	private function narrower(array $catalogue, ?AbstractPlatform $platform = null, string $prefix = 'oc_'): MagicJsonColumnNarrower {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform ?? new MariaDb1060Platform());
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($catalogue): IPreparedStatement {
				$view = match (true) {
					str_contains($sql, 'information_schema.columns') => 'columns',
					str_contains($sql, 'information_schema.check_constraints') => 'checks',
					default => 'tables',
				};
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('execute')->willReturnCallback(
					function (array $params = []) use ($sql): IResult {
						$this->queries[] = [$sql, $params];
						return $this->createMock(IResult::class);
					}
				);
				$statement->method('fetchAll')->willReturn($catalogue[$view] ?? []);
				return $statement;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				foreach ($this->refuse as $table) {
					if (str_contains($sql, '`' . $table . '`') === true) {
						throw new RuntimeException('Data too long for column');
					}
				}

				$this->statements[] = $sql;
				return 0;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = '') => ($key === 'dbtableprefix' ? $prefix : $default));

		return new MagicJsonColumnNarrower($db, $config);
	}//end narrower()

	/**
	 * A catalogue with a large and a small table that hold JSON in LONGTEXT.
	 *
	 * @return array{columns: list<array>, checks: list<array>, tables: list<array>}
	 */
	private static function catalogue(): array {
		return [
			'columns' => [
				['t' => 'oc_openregister_table_1_2', 'c' => '_files', 'n' => 'YES', 'd' => 'NULL'],
				['t' => 'oc_openregister_table_1_2', 'c' => 'tags', 'n' => 'NO', 'd' => "'[]'"],
				['t' => 'oc_openregister_table_1_2', 'c' => 'notes', 'n' => 'YES', 'd' => null],
				['t' => 'oc_openregister_table_3_4', 'c' => '_files', 'n' => 'YES', 'd' => 'NULL'],
			],
			'checks' => [
				['t' => 'oc_openregister_table_1_2', 'c' => '_files', 'k' => 'json_valid(`_files`)'],
				['t' => 'oc_openregister_table_1_2', 'c' => 'tags', 'k' => 'json_valid(`tags`)'],
				// A table-level check that merely mentions a column declares nothing about its type.
				['t' => 'oc_openregister_table_1_2', 'c' => 'notes_len', 'k' => 'char_length(`notes`) < 10'],
				['t' => 'oc_openregister_table_3_4', 'c' => '_files', 'k' => 'json_valid(`_files`)'],
			],
			'tables' => [
				['t' => 'oc_openregister_table_1_2', 'b' => 900],
				['t' => 'oc_openregister_table_3_4', 'b' => 100],
				['t' => 'oc_openregister_table_5_6', 'b' => 5],
			],
		];
	}//end catalogue()

	public function testOnlyMariaDbHasColumnsToNarrow(): void {
		$this->assertTrue($this->narrower([])->applies());
		$this->assertFalse($this->narrower([], new MySQLPlatform())->applies());
		$this->assertFalse($this->narrower([], new PostgreSQLPlatform())->applies());
	}//end testOnlyMariaDbHasColumnsToNarrow()

	public function testPendingListsJsonColumnsPerTableSmallestTableFirst(): void {
		$pending = $this->narrower(self::catalogue())->pending();

		$this->assertSame(['oc_openregister_table_3_4', 'oc_openregister_table_1_2'], array_keys($pending));
		$this->assertSame(100, $pending['oc_openregister_table_3_4']['bytes']);
		$this->assertSame(
			[
				['name' => '_files', 'nullable' => true, 'default' => null],
				['name' => 'tags', 'nullable' => false, 'default' => "'[]'"],
			],
			$pending['oc_openregister_table_1_2']['columns']
		);
	}//end testPendingListsJsonColumnsPerTableSmallestTableFirst()

	public function testALongTextColumnWithoutTheJsonCheckIsLeftAlone(): void {
		$pending = $this->narrower(self::catalogue())->pending();

		$this->assertNotContains('notes', array_column($pending['oc_openregister_table_1_2']['columns'], 'name'));
	}//end testALongTextColumnWithoutTheJsonCheckIsLeftAlone()

	public function testTheCatalogueIsAskedForMagicTablesOnlyWithWildcardsEscaped(): void {
		$this->narrower(self::catalogue(), null, 'my_')->pending();

		$this->assertCount(3, $this->queries);
		foreach ($this->queries as [$sql, $params]) {
			$this->assertStringContainsString('DATABASE()', $sql);
			$this->assertSame(['my\\_openregister\\_table\\_%'], $params);
		}
	}//end testTheCatalogueIsAskedForMagicTablesOnlyWithWildcardsEscaped()

	public function testNothingLeftCostsOneCatalogueQuery(): void {
		$result = $this->narrower(['columns' => []])->narrowPending();

		$this->assertSame(['narrowed' => [], 'failed' => [], 'left' => []], $result);
		$this->assertCount(1, $this->queries);
		$this->assertSame([], $this->statements);
	}//end testNothingLeftCostsOneCatalogueQuery()

	public function testEachTableIsNarrowedInOneStatementUnderStrictMode(): void {
		$result = $this->narrower(self::catalogue())->narrowPending();

		$this->assertSame(['oc_openregister_table_3_4', 'oc_openregister_table_1_2'], $result['narrowed']);
		$this->assertSame(
			[
				'SET @openregister_sql_mode = @@SESSION.sql_mode',
				"SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')",
				'ALTER TABLE `oc_openregister_table_3_4` MODIFY `_files` ' . self::TEXT . ' CHECK (json_valid(`_files`))',
				'ALTER TABLE `oc_openregister_table_1_2` MODIFY `_files` ' . self::TEXT . ' CHECK (json_valid(`_files`)),'
					. ' MODIFY `tags` ' . self::TEXT . " NOT NULL DEFAULT '[]' CHECK (json_valid(`tags`))",
				'SET SESSION sql_mode = @openregister_sql_mode',
			],
			$this->statements
		);
	}//end testEachTableIsNarrowedInOneStatementUnderStrictMode()

	public function testATableLargerThanTheLimitIsLeft(): void {
		$result = $this->narrower(self::catalogue())->narrowPending(500);

		$this->assertSame(['oc_openregister_table_3_4'], $result['narrowed']);
		$this->assertSame(['oc_openregister_table_1_2'], $result['left']);
	}//end testATableLargerThanTheLimitIsLeft()

	public function testAnExhaustedBudgetLeavesTheRemainingTables(): void {
		$result = $this->narrower(self::catalogue())->narrowPending(null, 0.0);

		$this->assertSame([], $result['narrowed']);
		$this->assertSame(['oc_openregister_table_3_4', 'oc_openregister_table_1_2'], $result['left']);
	}//end testAnExhaustedBudgetLeavesTheRemainingTables()

	public function testARefusedTableIsReportedAndTheRestStillRunWithTheModeRestored(): void {
		$this->refuse = ['oc_openregister_table_3_4'];

		$result = $this->narrower(self::catalogue())->narrowPending();

		$this->assertSame(['oc_openregister_table_3_4' => 'Data too long for column'], $result['failed']);
		$this->assertSame(['oc_openregister_table_1_2'], $result['narrowed']);
		$this->assertSame('SET SESSION sql_mode = @openregister_sql_mode', end($this->statements));
	}//end testARefusedTableIsReportedAndTheRestStillRunWithTheModeRestored()
}//end class
