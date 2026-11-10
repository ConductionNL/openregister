<?php

/**
 * MagicTableCreator writes one CREATE TABLE statement per platform from a column list.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicTableSql;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Exception;
use OCA\OpenRegister\Db\MagicMapper\MagicTableCreator;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableDdl
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 */
class MagicTableCreatorTest extends TestCase {

	/**
	 * Statements the connection received.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * The logger behind the creator.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A creator on the given platform that records each statement.
	 *
	 * @param AbstractPlatform $platform The platform.
	 * @param Exception|null   $failure  What executeStatement throws, if anything.
	 *
	 * @return MagicTableCreator
	 */
	private function creator(AbstractPlatform $platform, ?Exception $failure = null): MagicTableCreator {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($failure): int {
				$this->statements[] = $sql;
				if ($failure !== null) {
					throw $failure;
				}

				return 0;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->with('dbtableprefix', 'oc_')->willReturn('nc_');
		$this->logger = $this->createMock(LoggerInterface::class);
		return new MagicTableCreator(new MagicTableSql($db, $config, $this->logger));
	}//end creator()

	/**
	 * The columns of a typical magic table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function columns(): array {
		return [
			['name' => '_id', 'type' => 'bigint', 'autoincrement' => true, 'primary' => true, 'nullable' => false],
			['name' => '_uuid', 'type' => 'string', 'length' => 36, 'unique' => true, 'nullable' => false],
			['name' => 'title', 'type' => 'string', 'default' => "O'Brien"],
			['name' => 'active', 'type' => 'boolean', 'default' => true],
			['name' => 'archived', 'type' => 'boolean', 'default' => false],
			['name' => 'score', 'type' => 'integer', 'default' => 5],
		];
	}//end columns()

	/**
	 * MySQL: backticks, AUTO_INCREMENT, a trailing primary key and a named unique constraint.
	 *
	 * @return void
	 */
	public function testMysqlStatement(): void {
		$this->creator(new MySQLPlatform())->create('openregister_table_1_2', self::columns());

		$this->assertSame(
			['CREATE TABLE IF NOT EXISTS `nc_openregister_table_1_2` ('
			. '`_id` BIGINT NOT NULL AUTO_INCREMENT, '
			. '`_uuid` VARCHAR(36) NOT NULL, '
			. "`title` VARCHAR(255) DEFAULT 'O''Brien', "
			. '`active` BOOLEAN DEFAULT TRUE, '
			. '`archived` BOOLEAN DEFAULT FALSE, '
			. '`score` INTEGER DEFAULT 5'
			. ', PRIMARY KEY (`_id`)'
			. ', CONSTRAINT `openregister_table_1_2_uuid_uq` UNIQUE (`_uuid`))'],
			$this->statements
		);
	}//end testMysqlStatement()

	/**
	 * PostgreSQL: double quotes and BIGSERIAL, primary key still declared after the columns.
	 *
	 * @return void
	 */
	public function testPostgresStatement(): void {
		$this->creator(new PostgreSQLPlatform())->create('t', self::columns());

		$sql = $this->statements[0];
		$this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS "nc_t" ("_id" BIGSERIAL, ', $sql);
		$this->assertStringContainsString(', PRIMARY KEY ("_id")', $sql);
		$this->assertStringContainsString(', CONSTRAINT "t_uuid_uq" UNIQUE ("_uuid")', $sql);
		$this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
	}//end testPostgresStatement()

	/**
	 * SQLite: the primary key is inline on the autoincrement column and not repeated.
	 *
	 * @return void
	 */
	public function testSqliteInlinesThePrimaryKey(): void {
		$this->creator(new SqlitePlatform())->create('t', self::columns());

		$sql = $this->statements[0];
		$this->assertStringContainsString('`_id` INTEGER PRIMARY KEY AUTOINCREMENT, ', $sql);
		$this->assertStringNotContainsString(', PRIMARY KEY (', $sql);
		$this->assertStringContainsString('UNIQUE (`_uuid`)', $sql);
	}//end testSqliteInlinesThePrimaryKey()

	/**
	 * A table without primary or unique columns gets no constraints, and nullable is the default.
	 *
	 * @return void
	 */
	public function testPlainColumnsGetNoConstraints(): void {
		$this->creator(new MySQLPlatform())->create('t', [['name' => 'note', 'type' => 'text', 'nullable' => true]]);

		$this->assertSame(['CREATE TABLE IF NOT EXISTS `nc_t` (`note` TEXT)'], $this->statements);
	}//end testPlainColumnsGetNoConstraints()

	/**
	 * A successful create logs the full table name and the column names.
	 *
	 * @return void
	 */
	public function testASuccessfulCreateIsLogged(): void {
		$creator = $this->creator(new MySQLPlatform());
		$this->logger->expects($this->once())->method('debug')->with(
			'[MagicMapper] Created table with columns',
			['tableName' => 't', 'fullTableName' => 'nc_t', 'columns' => ['a', 'b']]
		);
		$this->logger->expects($this->never())->method('error');

		$creator->create('t', [['name' => 'a', 'type' => 'text'], ['name' => 'b', 'type' => 'text']]);
	}//end testASuccessfulCreateIsLogged()

	/**
	 * A failing statement is logged and rethrown with the table name, keeping the cause.
	 *
	 * @return void
	 */
	public function testAFailingCreateIsLoggedAndWrapped(): void {
		$cause = new RuntimeException('table exists differently');
		$creator = $this->creator(new MySQLPlatform(), $cause);
		$this->logger->expects($this->once())->method('error')->with(
			'[MagicMapper] Failed to create table',
			['tableName' => 't', 'error' => 'table exists differently']
		);
		$this->logger->expects($this->never())->method('debug');

		try {
			$creator->create('t', [['name' => 'a', 'type' => 'text']]);
			$this->fail('create() should have thrown');
		} catch (Exception $e) {
			$this->assertSame('Failed to create table t: table exists differently', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAFailingCreateIsLoggedAndWrapped()
}//end class
