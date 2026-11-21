<?php

/**
 * On PostgreSQL an indexed string column also gets a lower() ordering index; MySQL/MariaDB get none.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-insensitive-ordering/specs/zoeken-filteren/spec.md#requirement-ordering-on-a-string-property-ignores-letter-case-on-every-supported-database-req-cio-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 */
class MagicMapperLowerIndexTest extends TestCase {

	/**
	 * Every executed statement.
	 *
	 * @var array<int, string>
	 */
	private array $capturedSql = [];

	protected function setUp(): void {
		parent::setUp();
		$this->capturedSql = [];
	}//end setUp()

	/**
	 * A mapper whose db records every executeStatement, on the given platform.
	 *
	 * @param AbstractPlatform $platform    The platform.
	 * @param int              $pgTrgmCount The pg_trgm probe result.
	 *
	 * @return MagicMapper
	 */
	private function makeMapper(AbstractPlatform $platform, int $pgTrgmCount): MagicMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->capturedSql[] = $sql;
				return 0;
			}
		);

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($pgTrgmCount);
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($stmt);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturn('oc_');

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'logger' => $this->createMock(LoggerInterface::class), 'config' => $config] as $name => $value) {
			$prop = $reflection->getProperty($name);
			$prop->setAccessible(true);
			$prop->setValue($mapper, $value);
		}

		return $mapper;
	}//end makeMapper()

	/**
	 * Run createTableIndexes with the given properties.
	 *
	 * @param MagicMapper $mapper     The mapper.
	 * @param string      $tableName  The table name.
	 * @param array       $properties The schema properties.
	 *
	 * @return void
	 */
	private function runCreateIndexes(MagicMapper $mapper, string $tableName, array $properties): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicMapper::class, 'createTableIndexes');
		$method->setAccessible(true);
		$method->invokeArgs($mapper, [$tableName, $schema]);
	}//end runCreateIndexes()

	/**
	 * Whether any executed SQL contains the needle.
	 *
	 * @param string $needle The needle.
	 *
	 * @return bool
	 */
	private function sqlHas(string $needle): bool {
		foreach ($this->capturedSql as $sql) {
			if (str_contains($sql, $needle) === true) {
				return true;
			}
		}

		return false;
	}//end sqlHas()

	public function testTheNameColumnGetsALowerIndexOnPostgres(): void {
		$mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);
		$this->runCreateIndexes($mapper, 'openregister_table_1_1', []);

		$this->assertTrue($this->sqlHas('openregister_table_1_1_name_lower_idx'));
		$this->assertTrue($this->sqlHas('(LOWER(CAST(_name AS TEXT)))'));
	}//end testTheNameColumnGetsALowerIndexOnPostgres()

	public function testAFacetableStringColumnGetsALowerIndexOnPostgres(): void {
		$mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);
		$this->runCreateIndexes($mapper, 'openregister_table_1_2', ['title' => ['type' => 'string', 'facetable' => true]]);

		$this->assertTrue($this->sqlHas('openregister_table_1_2_title_lower_idx'));
		$this->assertTrue($this->sqlHas('(LOWER(CAST("title" AS TEXT)))'));
	}//end testAFacetableStringColumnGetsALowerIndexOnPostgres()

	public function testANonStringOrUnindexedColumnGetsNoLowerIndex(): void {
		$mapper = $this->makeMapper($this->createMock(PostgreSQLPlatform::class), pgTrgmCount: 1);
		$this->runCreateIndexes(
			$mapper,
			'openregister_table_1_3',
			[
				'amount' => ['type' => 'number', 'facetable' => true],
				'plain' => ['type' => 'string'],
				'when' => ['type' => 'string', 'format' => 'date', 'facetable' => true],
			]
		);

		$this->assertFalse($this->sqlHas('amount_lower_idx'));
		$this->assertFalse($this->sqlHas('plain_lower_idx'));
		$this->assertFalse($this->sqlHas('when_lower_idx'));
	}//end testANonStringOrUnindexedColumnGetsNoLowerIndex()

	public function testMariaDbGetsNoLowerIndex(): void {
		$mapper = $this->makeMapper($this->createMock(MariaDBPlatform::class), pgTrgmCount: 0);
		$this->runCreateIndexes($mapper, 'openregister_table_1_4', ['title' => ['type' => 'string', 'facetable' => true]]);

		$this->assertFalse($this->sqlHas('_lower_idx'));
		$this->assertFalse($this->sqlHas('LOWER('));
	}//end testMariaDbGetsNoLowerIndex()
}//end class
