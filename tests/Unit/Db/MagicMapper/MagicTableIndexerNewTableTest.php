<?php

/**
 * MagicTableIndexerNewTableTest: a new table on MariaDB gets its standard indexes in one statement.
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
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchFolding
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexRun
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIdentityIndex
 *
 * @spec openspec/specs/upgrade-performance/spec.md#requirement-a-new-magic-table-gets-its-standard-indexes-in-one-statement
 */
class MagicTableIndexerNewTableTest extends TestCase {

	/**
	 * The ten standard indexes, in the order they are made.
	 */
	private const STANDARD = [
		'UNIQUE INDEX IF NOT EXISTS openregister_table_1_2_uuid_idx (_uuid)',
		'INDEX IF NOT EXISTS openregister_table_1_2_register_schema_idx (_register, _schema)',
		'INDEX IF NOT EXISTS openregister_table_1_2_organisation_idx (_organisation)',
		'INDEX IF NOT EXISTS openregister_table_1_2_owner_idx (_owner)',
		'INDEX IF NOT EXISTS openregister_table_1_2_created_idx (_created)',
		'INDEX IF NOT EXISTS openregister_table_1_2_updated_idx (_updated)',
		'INDEX IF NOT EXISTS openregister_table_1_2_name_idx (_name)',
		'INDEX IF NOT EXISTS openregister_table_1_2_slug_idx (_slug)',
		'INDEX IF NOT EXISTS openregister_table_1_2_uri_idx (_uri)',
		'INDEX IF NOT EXISTS openregister_table_1_2_last_user_activity_idx (_last_user_activity)',
	];

	/**
	 * The statements indexing a table without properties runs.
	 *
	 * @param AbstractPlatform $platform The database platform.
	 * @param string           $method   The MagicMapper method: for a new table or for an existing one.
	 *
	 * @return list<string>
	 */
	private function statements(AbstractPlatform $platform, string $method): array {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(0);
		$probe = $this->createMock(IPreparedStatement::class);
		$probe->method('execute')->willReturn($result);
		$db->method('prepare')->willReturn($probe);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $key, mixed $default = '') => $default);

		$reflection = new ReflectionClass(MagicMapper::class);
		$mapper = $reflection->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'config' => $config, 'logger' => $this->createMock(LoggerInterface::class)] as $name => $value) {
			$reflection->getProperty($name)->setValue($mapper, $value);
		}

		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([]);
		(new ReflectionMethod(MagicMapper::class, $method))->invoke($mapper, 'openregister_table_1_2', $schema);

		return $statements;
	}//end statements()

	public function testANewTableOnMariaDbGetsItsStandardIndexesInOneStatement(): void {
		$statements = $this->statements(new MariaDb1060Platform(), 'createNewTableIndexes');

		$this->assertSame('ALTER TABLE oc_openregister_table_1_2 ADD ' . implode(', ADD ', self::STANDARD), $statements[0]);
		$this->assertSame([], array_values(array_filter(array_slice($statements, 1), static fn (string $sql): bool => str_contains($sql, 'IF NOT EXISTS'))));
	}//end testANewTableOnMariaDbGetsItsStandardIndexesInOneStatement()

	public function testAnExistingTableOnMariaDbKeepsOneStatementPerIndex(): void {
		$statements = $this->statements(new MariaDb1060Platform(), 'createTableIndexes');

		foreach (self::STANDARD as $position => $index) {
			[$what, $columns] = explode(' (', $index, 2);
			$this->assertSame('CREATE ' . $what . ' ON oc_openregister_table_1_2 (' . $columns, $statements[$position]);
		}
	}//end testAnExistingTableOnMariaDbKeepsOneStatementPerIndex()

	public function testOtherPlatformsKeepOneStatementPerIndexForANewTableToo(): void {
		foreach ([new PostgreSQLPlatform(), new MySQLPlatform()] as $platform) {
			$statements = $this->statements($platform, 'createNewTableIndexes');

			$this->assertSame('CREATE UNIQUE INDEX IF NOT EXISTS openregister_table_1_2_uuid_idx ON oc_openregister_table_1_2 (_uuid)', $statements[0], $platform::class);
			$this->assertSame('CREATE INDEX IF NOT EXISTS openregister_table_1_2_owner_idx ON oc_openregister_table_1_2 (_owner)', $statements[3], $platform::class);
		}
	}//end testOtherPlatformsKeepOneStatementPerIndexForANewTableToo()
}//end class
