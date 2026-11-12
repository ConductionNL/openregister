<?php

/**
 * MagicColumnAdderTest: the ALTER TABLE statements that add a magic table's missing columns.
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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnAdder;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicColumnAdder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicColumnAdderTest extends TestCase {

	/**
	 * Statements the connection ran.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * Debug messages logged.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $debug = [];

	/**
	 * The adder on a connection that records statements and quotes like MySQL.
	 *
	 * @param RuntimeException|null $failure Thrown by the connection on the first statement.
	 *
	 * @return MagicColumnAdder
	 */
	private function adder(?RuntimeException $failure = null): MagicColumnAdder {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failure): int {
				if ($failure !== null) {
					throw $failure;
				}

				$this->statements[] = $sql;
				return 0;
			}
		);
		$db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->debug[] = [(string) $message, $context];
			}
		);

		return new MagicColumnAdder($db, $logger);
	}//end adder()

	/**
	 * A table sync.
	 *
	 * @param array<string, array> $current    Live columns.
	 * @param array<string, array> $required   Required columns.
	 * @param bool                 $isPostgres The platform.
	 *
	 * @return TableStructureSync
	 */
	private function sync(array $current, array $required, bool $isPostgres = false): TableStructureSync {
		return new TableStructureSync('openregister_table_1_2', 'oc_openregister_table_1_2', new MagicColumnDrift($current, $required), $isPostgres);
	}//end sync()

	public function testNothingMissingRunsNoStatement(): void {
		$sync = $this->sync(['title' => ['type' => 'text']], ['title' => ['type' => 'string']]);

		$this->assertSame([], $this->adder()->addMissing($sync));
		$this->assertSame([], $this->statements);
	}//end testNothingMissingRunsNoStatement()

	public function testAddsEachMissingColumnUnderItsSanitizedNameAndType(): void {
		$sync = $this->sync(
			['title' => ['type' => 'text']],
			[
				'title' => ['type' => 'string'],
				'firstName' => ['type' => 'string', 'length' => 80],
				'amount' => ['type' => 'decimal', 'name' => 'amount_eur', 'precision' => 12, 'scale' => 4],
			]
		);

		$added = $this->adder()->addMissing($sync);

		$this->assertSame(['first_name', 'amount_eur'], $added);
		$this->assertSame(
			[
				'ALTER TABLE `oc_openregister_table_1_2` ADD COLUMN `first_name` VARCHAR(80)',
				'ALTER TABLE `oc_openregister_table_1_2` ADD COLUMN `amount_eur` DECIMAL(12,4)',
			],
			$this->statements
		);
		$this->assertSame(
			['tableName' => 'openregister_table_1_2', 'propertyName' => 'firstName', 'columnName' => 'first_name', 'columnType' => 'string'],
			$this->debug[0][1]
		);
	}//end testAddsEachMissingColumnUnderItsSanitizedNameAndType()

	public function testARequiredColumnWithADefaultIsAddedNotNullWithThatDefault(): void {
		$sync = $this->sync([], ['active' => ['type' => 'boolean', 'nullable' => false, 'default' => true]], true);

		$this->adder()->addMissing($sync);

		$this->assertSame(['ALTER TABLE "oc_openregister_table_1_2" ADD COLUMN "active" BOOLEAN NOT NULL DEFAULT TRUE'], $this->statements);
	}//end testARequiredColumnWithADefaultIsAddedNotNullWithThatDefault()

	public function testARequiredColumnWithoutADefaultStaysNullableAndSaysWhy(): void {
		$sync = $this->sync([], ['code' => ['type' => 'string', 'nullable' => false]]);

		$this->adder()->addMissing($sync);

		$this->assertSame(['ALTER TABLE `oc_openregister_table_1_2` ADD COLUMN `code` VARCHAR(255)'], $this->statements);
		$this->assertSame('[MagicMapper] Adding required column as NULLABLE on existing table (no default to backfill rows)', $this->debug[1][0]);
		$this->assertSame(['tableName' => 'openregister_table_1_2', 'columnName' => 'code'], $this->debug[1][1]);
	}//end testARequiredColumnWithoutADefaultStaysNullableAndSaysWhy()

	public function testANullableColumnKeepsItsDefaultWithoutNotNull(): void {
		$sync = $this->sync([], ['count' => ['type' => 'integer', 'default' => 0]]);

		$this->adder()->addMissing($sync);

		$this->assertSame(['ALTER TABLE `oc_openregister_table_1_2` ADD COLUMN `count` INTEGER DEFAULT 0'], $this->statements);
		$this->assertCount(1, $this->debug);
	}//end testANullableColumnKeepsItsDefaultWithoutNotNull()

	public function testAFailingStatementStopsTheSync(): void {
		$sync = $this->sync([], ['a' => ['type' => 'text'], 'b' => ['type' => 'text']]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('duplicate column');
		$this->adder(new RuntimeException('duplicate column'))->addMissing($sync);
	}//end testAFailingStatementStopsTheSync()

	public function testDefaultLiteralWritesBooleansAsKeywords(): void {
		$adder = $this->adder();

		$this->assertSame('TRUE', $adder->defaultLiteral(true));
		$this->assertSame('FALSE', $adder->defaultLiteral(false));
	}//end testDefaultLiteralWritesBooleansAsKeywords()

	public function testDefaultLiteralQuotesStringsThroughThePlatform(): void {
		$this->assertSame("'O''Brien'", $this->adder()->defaultLiteral("O'Brien"));
	}//end testDefaultLiteralQuotesStringsThroughThePlatform()

	public function testDefaultLiteralWritesNumbersAsIs(): void {
		$adder = $this->adder();

		$this->assertSame('42', $adder->defaultLiteral(42));
		$this->assertSame('1.5', $adder->defaultLiteral(1.5));
	}//end testDefaultLiteralWritesNumbersAsIs()
}//end class
