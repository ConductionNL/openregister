<?php

/**
 * MagicMapper: a schema `number` property's column keeps its decimals, an old
 * narrower column is widened, and a value the column cannot hold is refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @spec openspec/specs/magic-table-columns/spec.md
 */
class MagicMapperNumberColumnTest extends TestCase {

	private IDBConnection&MockObject $db;

	/**
	 * A MagicMapper without its constructor, with the db, config, logger and container it reads.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapper(): MagicMapper {
		$this->db = $this->createMock(IDBConnection::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn ($key, $default) => $key === 'dbtableprefix' ? 'oc_' : $default);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $this->db, 'config' => $config, 'logger' => $this->createMock(LoggerInterface::class), 'container' => $this->createMock(ContainerInterface::class)] as $name => $value) {
			$property = $reflection->getProperty($name);
			$property->setAccessible(true);
			$property->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * Call a private MagicMapper method.
	 *
	 * @param MagicMapper $mapper The mapper.
	 * @param string      $method The method.
	 * @param array       $args   Its arguments.
	 *
	 * @return mixed The result.
	 */
	private function call(MagicMapper $mapper, string $method, array $args): mixed {
		$reflection = new ReflectionMethod(MagicMapper::class, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($mapper, $args);
	}//end call()

	public function testANumberPropertyGetsTwentySixNotTenTwo(): void {
		$mapper = $this->mapper();
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));

		$column = $this->call($mapper, 'mapSchemaPropertyToColumn', ['weight', ['type' => 'number']]);

		$this->assertSame('decimal', $column['type']);
		$this->assertSame(20, $column['precision']);
		$this->assertSame(6, $column['scale']);
		$this->assertSame('DECIMAL(20,6)', $this->call($mapper, 'mapColumnTypeToSQL', ['decimal', $column]));
	}//end testANumberPropertyGetsTwentySixNotTenTwo()

	public function testMultipleOfSetsTheColumnScale(): void {
		$column = $this->call($this->mapper(), 'mapSchemaPropertyToColumn', ['weight', ['type' => 'number', 'multipleOf' => 0.001]]);

		$this->assertSame(17, $column['precision']);
		$this->assertSame(3, $column['scale']);
	}//end testMultipleOfSetsTheColumnScale()

	public function testFiveGramsRoundTrips(): void {
		$mapper = $this->mapper();
		$column = $this->call($mapper, 'mapSchemaPropertyToColumn', ['weight', ['type' => 'number']]);
		$schema = $this->schema(properties: ['weight' => ['type' => 'number']]);

		$prepared = $this->call($mapper, 'prepareObjectDataForTable', [['weight' => 0.005], $this->createMock(Register::class), $schema]);

		$this->assertSame(0.005, $prepared['weight']);
		// What a DECIMAL(20,6) column returns for 0.005, read back the way every magic-table read does.
		$stored = number_format($prepared['weight'], $column['scale'], '.', '');
		$this->assertSame('0.005000', $stored);
		$this->assertSame(0.005, (new SchemaTypeConverter())->convertValue(value: $stored, schemaType: 'number'));
	}//end testFiveGramsRoundTrips()

	public function testAnOldTwoDecimalColumnRefusesFiveGramsOnSaveWithoutAlteringIt(): void {
		$mapper = $this->mapper();
		$this->columnSizes(rows: [['col' => 'weight', 'numeric_precision' => 10, 'numeric_scale' => 2]]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage("'weight'");
		$this->call($mapper, 'refuseNumbersTheColumnsWouldRound', [['weight' => 0.005], 'openregister_table_old']);
	}//end testAnOldTwoDecimalColumnRefusesFiveGramsOnSaveWithoutAlteringIt()

	public function testAWidenedColumnTakesFiveGrams(): void {
		$mapper = $this->mapper();
		$this->columnSizes(rows: [['col' => 'weight', 'numeric_precision' => 20, 'numeric_scale' => 6]]);
		$this->db->expects($this->never())->method('executeStatement');

		$this->call($mapper, 'refuseNumbersTheColumnsWouldRound', [['weight' => 0.005], 'openregister_table_new']);
		$this->addToAssertionCount(1);
	}//end testAWidenedColumnTakesFiveGrams()

	public function testTheUpgradeWidensEveryNumberColumnOfATable(): void {
		$mapper = $this->mapper();
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$statement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$statement->method('fetchAll')->willReturn([
			['column_name' => 'weight', 'data_type' => 'numeric', 'character_maximum_length' => null, 'numeric_precision' => 10, 'numeric_scale' => 2, 'is_nullable' => 'YES', 'column_default' => null],
			['column_name' => 'rate', 'data_type' => 'numeric', 'character_maximum_length' => null, 'numeric_precision' => 20, 'numeric_scale' => 6, 'is_nullable' => 'YES', 'column_default' => null],
			['column_name' => 'title', 'data_type' => 'character varying', 'character_maximum_length' => 255, 'numeric_precision' => null, 'numeric_scale' => null, 'is_nullable' => 'YES', 'column_default' => null],
		]);
		$this->db->method('prepare')->willReturn($statement);
		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
			$statements[] = $sql;
			return 0;
		});

		$this->assertSame(['weight'], $mapper->widenNumberColumnsToDefault(tableName: 'openregister_table_up'));
		$this->assertSame(['ALTER TABLE "oc_openregister_table_up" ALTER COLUMN "weight" TYPE DECIMAL(20,6)'], $statements);
	}//end testTheUpgradeWidensEveryNumberColumnOfATable()

	/**
	 * Let the information_schema read of the column sizes return these rows.
	 *
	 * @param array $rows The rows (col, numeric_precision, numeric_scale).
	 *
	 * @return void
	 */
	private function columnSizes(array $rows): void {
		$statement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$this->db->method('prepare')->willReturn($statement);
	}//end columnSizes()

	public function testAnOldTwoDecimalColumnIsWidenedOnPostgres(): void {
		$mapper = $this->mapper();
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
			$statements[] = $sql;
			return 0;
		});

		$current = ['weight' => ['name' => 'weight', 'type' => 'numeric', 'precision' => 10, 'scale' => 2, 'nullable' => true, 'default' => null]];
		$required = ['weight' => $this->call($mapper, 'mapSchemaPropertyToColumn', ['weight', ['type' => 'number']])];

		$this->assertSame(['weight'], $mapper->findDecimalColumnsNeedingWiden(currentColumns: $current, requiredColumns: $required));
		$this->assertSame(['weight'], $mapper->widenDecimalColumns(tableName: 'openregister_table_ab', currentColumns: $current, requiredColumns: $required));
		$this->assertSame(['ALTER TABLE "oc_openregister_table_ab" ALTER COLUMN "weight" TYPE DECIMAL(20,6)'], $statements);
	}//end testAnOldTwoDecimalColumnIsWidenedOnPostgres()

	public function testAnOldTwoDecimalColumnIsWidenedOnMysqlKeepingNotNull(): void {
		$mapper = $this->mapper();
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
		$statements = [];
		$this->db->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
			$statements[] = $sql;
			return 0;
		});

		$current = ['weight' => ['name' => 'weight', 'type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => false, 'default' => null]];
		$required = ['weight' => $this->call($mapper, 'mapSchemaPropertyToColumn', ['weight', ['type' => 'number', 'required' => true]])];

		$mapper->widenDecimalColumns(tableName: 'openregister_table_ab', currentColumns: $current, requiredColumns: $required);

		$this->assertSame(['ALTER TABLE `oc_openregister_table_ab` MODIFY `weight` DECIMAL(20,6) NOT NULL'], $statements);
	}//end testAnOldTwoDecimalColumnIsWidenedOnMysqlKeepingNotNull()

	public function testAWideEnoughColumnIsLeftAlone(): void {
		$mapper = $this->mapper();
		$current = ['weight' => ['name' => 'weight', 'type' => 'numeric', 'precision' => 20, 'scale' => 6, 'nullable' => true, 'default' => null]];
		$required = ['weight' => $this->call($mapper, 'mapSchemaPropertyToColumn', ['weight', ['type' => 'number', 'multipleOf' => 0.01]])];

		$this->assertSame([], $mapper->findDecimalColumnsNeedingWiden(currentColumns: $current, requiredColumns: $required));
	}//end testAWideEnoughColumnIsLeftAlone()

	/**
	 * A schema with the given properties.
	 *
	 * @param array $properties The schema properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties($properties);
		return $schema;
	}//end schema()
}//end class
