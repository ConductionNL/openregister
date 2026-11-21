<?php

/**
 * MagicMapper::tableIndexes() reads the catalogue of the register+schema table.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-property-index-switch/specs/zoeken-filteren/spec.md#requirement-a-property-can-ask-for-an-index-without-being-a-facet
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * The table's index names, described against the schema; nothing for a table that does not exist.
 */
final class MagicMapperTableIndexesTest extends TestCase {

	/** @var array<int, array{0: string, 1: array<string, mixed>}> */
	private array $queries = [];

	/**
	 * A mapper over a table that may or may not exist.
	 *
	 * @param bool          $exists   Whether the table exists.
	 * @param bool          $postgres Whether the platform is PostgreSQL.
	 * @param array<int, mixed> $rows The catalogue rows.
	 *
	 * @return MagicMapper
	 */
	private function mapper(bool $exists, bool $postgres, array $rows): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($postgres === true ? new PostgreSQLPlatform() : new MySQLPlatform());
		$db->expects($this->never())->method('executeStatement');
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) use ($result): IResult {
				$this->queries[] = [$sql, $params];
				return $result;
			}
		);

		$handler = $this->createMock(MagicTableHandler::class);
		$handler->method('existsTableForRegisterSchema')->willReturn($exists);
		$handler->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_12');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper     = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => new NullLogger(), 'config' => $config, 'tableHandler' => $handler] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * The schema with one indexed date.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['registratiedatum' => ['type' => 'string', 'indexed' => true]]);

		return $schema;
	}//end schema()

	public function testTheIndexesOfAnExistingTableAreDescribed(): void {
		$indexes = $this->mapper(exists: true, postgres: true, rows: [['indexname' => 'openregister_table_3_12_registratiedatum_sort_idx']])
			->tableIndexes(register: new Register(), schema: self::schema());

		self::assertSame(
			[['name' => 'openregister_table_3_12_registratiedatum_sort_idx', 'property' => 'registratiedatum', 'column' => 'registratiedatum', 'kind' => 'btree', 'flag' => 'indexed']],
			$indexes
		);
		self::assertStringContainsString('pg_indexes', $this->queries[0][0]);
		self::assertSame(['tbl' => 'oc_openregister_table_3_12'], $this->queries[0][1]);
	}//end testTheIndexesOfAnExistingTableAreDescribed()

	public function testMariaDbReadsItsOwnCatalogue(): void {
		$this->mapper(exists: true, postgres: false, rows: [])->tableIndexes(register: new Register(), schema: self::schema());

		self::assertStringContainsString('information_schema.STATISTICS', $this->queries[0][0]);
	}//end testMariaDbReadsItsOwnCatalogue()

	public function testATableThatDoesNotExistHasNoIndexes(): void {
		self::assertSame([], $this->mapper(exists: false, postgres: true, rows: [['indexname' => 'x']])->tableIndexes(register: new Register(), schema: self::schema()));
		self::assertSame([], $this->queries);
	}//end testATableThatDoesNotExistHasNoIndexes()
}//end class
