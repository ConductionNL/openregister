<?php

/**
 * MagicMapperMariaDbStorageTest: how a magic table stores JSON and gets its base indexes, per database.
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
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-new-magic-table-gets-its-standard-indexes-in-one-statement
 */
class MagicMapperMariaDbStorageTest extends TestCase {

	private const TABLE = 'openregister_table_1_2';

	private const MEDIUMTEXT = 'MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';

	/**
	 * Every statement the mapper executed, in order.
	 *
	 * @var list<string>
	 */
	private array $sql = [];

	/**
	 * A mapper with only the database, logger and configuration its DDL code touches.
	 *
	 * @param AbstractPlatform $platform The database platform.
	 *
	 * @return MagicMapper
	 */
	private function mapper(AbstractPlatform $platform): MagicMapper {
		$this->sql = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->sql[] = $sql;
				return 0;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$class = new ReflectionClass(MagicMapper::class);
		$mapper = $class->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $this->createMock(LoggerInterface::class), 'config' => $config] as $name => $value) {
			$class->getProperty($name)->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * Call a private method of the mapper.
	 *
	 * @param MagicMapper $mapper    The mapper.
	 * @param string      $method    The method name.
	 * @param array       $arguments Its arguments.
	 *
	 * @return mixed
	 */
	private function call(MagicMapper $mapper, string $method, array $arguments): mixed {
		return (new ReflectionMethod(MagicMapper::class, $method))->invokeArgs($mapper, $arguments);
	}//end call()

	/**
	 * Create the indexes of the test table.
	 *
	 * @param AbstractPlatform $platform The database platform.
	 * @param bool             $newTable Whether the table was created just now.
	 *
	 * @return void
	 */
	private function createIndexes(AbstractPlatform $platform, bool $newTable): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([]);

		$this->call($this->mapper($platform), 'createTableIndexes', [self::TABLE, $this->createMock(Register::class), $schema, $newTable]);
	}//end createIndexes()

	/**
	 * The names of the ten base indexes, in the order they are created.
	 *
	 * @return list<string>
	 */
	private static function baseIndexNames(): array {
		$names = [];
		foreach (['uuid', 'register_schema', 'organisation', 'owner', 'created', 'updated', 'name', 'slug', 'uri', 'last_user_activity'] as $suffix) {
			$names[] = self::TABLE . '_' . $suffix . '_idx';
		}

		return $names;
	}//end baseIndexNames()

	public function testAJsonColumnIsCheckedMediumTextOnMariaDbAndNativeEverywhereElse(): void {
		$cases = [
			['json', new MariaDBPlatform(), self::MEDIUMTEXT],
			['json_ordered', new MariaDBPlatform(), self::MEDIUMTEXT],
			['json', new MySQLPlatform(), 'JSON'],
			['json_ordered', new MySQLPlatform(), 'JSON'],
			['json', new PostgreSQLPlatform(), 'JSONB'],
			['json_ordered', new PostgreSQLPlatform(), 'JSON'],
		];
		foreach ($cases as [$type, $platform, $expected]) {
			$this->assertSame($expected, $this->call($this->mapper($platform), 'mapColumnTypeToSQL', [$type, []]), $type . ' on ' . $platform::class);
		}
	}//end testAJsonColumnIsCheckedMediumTextOnMariaDbAndNativeEverywhereElse()

	public function testANewTableOnMariaDbDeclaresEachJsonColumnWithItsCheckLast(): void {
		$mapper = $this->mapper(new MariaDBPlatform());
		$this->call(
			$mapper,
			'createTable',
			[
				self::TABLE,
				[
					['name' => '_id', 'type' => 'bigint', 'nullable' => false, 'autoincrement' => true, 'primary' => true],
					['name' => '_files', 'type' => 'json', 'nullable' => true],
					['name' => 'tags', 'type' => 'json_ordered', 'nullable' => false, 'default' => '[]'],
					['name' => 'title', 'type' => 'string', 'length' => 64],
				],
			]
		);

		$create = $this->sql[0];
		$this->assertStringContainsString('`_files` ' . self::MEDIUMTEXT . ' CHECK (json_valid(`_files`)),', $create);
		// The check comes after NOT NULL and DEFAULT: MariaDB refuses it anywhere else.
		$this->assertStringContainsString('`tags` ' . self::MEDIUMTEXT . " NOT NULL DEFAULT '[]' CHECK (json_valid(`tags`)),", $create);
		$this->assertSame(2, substr_count($create, 'json_valid('), 'only the JSON columns carry the check');
		$this->assertStringNotContainsString(' JSON', $create);
	}//end testANewTableOnMariaDbDeclaresEachJsonColumnWithItsCheckLast()

	public function testANewTableElsewhereKeepsTheNativeJsonTypeAndNoCheck(): void {
		$columns = [['name' => '_files', 'type' => 'json', 'nullable' => true]];

		$this->call($this->mapper(new MySQLPlatform()), 'createTable', [self::TABLE, $columns]);
		$this->assertStringContainsString('`_files` JSON)', $this->sql[0]);
		$this->assertStringNotContainsString('json_valid', $this->sql[0]);

		$this->call($this->mapper(new PostgreSQLPlatform()), 'createTable', [self::TABLE, $columns]);
		$this->assertStringContainsString('"_files" JSONB)', $this->sql[0]);
		$this->assertStringNotContainsString('json_valid', $this->sql[0]);
	}//end testANewTableElsewhereKeepsTheNativeJsonTypeAndNoCheck()

	public function testAColumnAddedOnMariaDbCarriesTheCheck(): void {
		$sql = $this->mapper(new MariaDBPlatform())->buildAddColumnSql(self::TABLE, ['name' => '_groups', 'type' => 'json', 'default' => '[]', 'nullable' => false]);

		$this->assertSame(
			'ALTER TABLE `oc_' . self::TABLE . '` ADD COLUMN `_groups` ' . self::MEDIUMTEXT . " DEFAULT '[]' NOT NULL CHECK (json_valid(`_groups`))",
			$sql
		);
		$this->assertStringNotContainsString(
			'json_valid',
			$this->mapper(new MySQLPlatform())->buildAddColumnSql(self::TABLE, ['name' => '_groups', 'type' => 'json'])
		);
	}//end testAColumnAddedOnMariaDbCarriesTheCheck()

	/**
	 * A MODIFY restates the whole column: without the check in it, the column would lose its validation.
	 *
	 * @return void
	 */
	public function testANullabilityChangeOnMariaDbRestatesTheCheck(): void {
		$quoted = '`oc_' . self::TABLE . '`';
		$mapper = $this->mapper(new MariaDBPlatform());

		$this->call($mapper, 'deRequireColumns', [self::TABLE, $quoted, ['tags' => ['nullable' => false]], ['tags' => ['name' => 'tags', 'type' => 'json', 'nullable' => true]], false]);
		$this->call($mapper, 'reRequireColumns', [self::TABLE, $quoted, ['tags' => ['nullable' => true]], ['tags' => ['name' => 'tags', 'type' => 'json', 'nullable' => false]], false]);

		$this->assertContains('ALTER TABLE ' . $quoted . ' MODIFY COLUMN `tags` ' . self::MEDIUMTEXT . ' NULL CHECK (json_valid(`tags`))', $this->sql);
		$this->assertContains('ALTER TABLE ' . $quoted . ' MODIFY COLUMN `tags` ' . self::MEDIUMTEXT . ' NOT NULL CHECK (json_valid(`tags`))', $this->sql);
	}//end testANullabilityChangeOnMariaDbRestatesTheCheck()

	public function testANewTableOnMariaDbGetsItsTenBaseIndexesInOneStatement(): void {
		$this->createIndexes(new MariaDBPlatform(), true);

		$alter = $this->sql[0];
		$this->assertStringStartsWith(
			'ALTER TABLE oc_' . self::TABLE . ' ADD UNIQUE INDEX IF NOT EXISTS ' . self::TABLE . '_uuid_idx (_uuid), '
			. 'ADD INDEX IF NOT EXISTS ' . self::TABLE . '_register_schema_idx (_register, _schema), ',
			$alter
		);
		$this->assertSame(10, substr_count($alter, ' IF NOT EXISTS '));
		foreach (self::baseIndexNames() as $name) {
			$this->assertStringContainsString(' ' . $name . ' (', $alter);
			$this->assertSame([], preg_grep('/^CREATE (UNIQUE )?INDEX IF NOT EXISTS ' . $name . ' /', $this->sql), $name . ' was also created on its own');
		}
	}//end testANewTableOnMariaDbGetsItsTenBaseIndexesInOneStatement()

	/**
	 * An older table can lack a column: one statement per index refuses that index, not all ten.
	 *
	 * @return void
	 */
	public function testAnExistingTableOnMariaDbKeepsOneStatementPerIndex(): void {
		$this->createIndexes(new MariaDBPlatform(), false);

		$this->assertSame('CREATE UNIQUE INDEX IF NOT EXISTS ' . self::TABLE . '_uuid_idx ON oc_' . self::TABLE . ' (_uuid)', $this->sql[0]);
		$this->assertSame('CREATE INDEX IF NOT EXISTS ' . self::TABLE . '_register_schema_idx ON oc_' . self::TABLE . ' (_register, _schema)', $this->sql[1]);
		foreach (self::baseIndexNames() as $position => $name) {
			$this->assertStringContainsString(' IF NOT EXISTS ' . $name . ' ON oc_' . self::TABLE . ' (', $this->sql[$position]);
		}

		$this->assertSame([], preg_grep('/^ALTER TABLE/', $this->sql));
	}//end testAnExistingTableOnMariaDbKeepsOneStatementPerIndex()

	/**
	 * `ADD INDEX IF NOT EXISTS` is MariaDB's syntax; MySQL and PostgreSQL do not take it.
	 *
	 * @return void
	 */
	public function testANewTableElsewhereKeepsOneStatementPerIndex(): void {
		foreach ([new MySQLPlatform(), new PostgreSQLPlatform()] as $platform) {
			$this->createIndexes($platform, true);

			$this->assertSame([], preg_grep('/^ALTER TABLE/', $this->sql), $platform::class);
			foreach (self::baseIndexNames() as $position => $name) {
				$this->assertStringContainsString(' IF NOT EXISTS ' . $name . ' ON oc_' . self::TABLE . ' (', $this->sql[$position], $platform::class);
			}
		}
	}//end testANewTableElsewhereKeepsOneStatementPerIndex()
}//end class
