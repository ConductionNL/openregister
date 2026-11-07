<?php

/**
 * Releasing an obsolete column restates its full live type on MySQL.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner;
use OCA\OpenRegister\Db\MagicMapper\MagicObsoleteColumns;
use OCA\OpenRegister\Db\MagicMapper\MagicTableColumnReader;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicObsoleteColumns
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableColumnReader
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNullabilityChange
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicObsoleteColumnsReleaseTest extends TestCase {
	private const ROW = [
		'column_name' => 'legacy',
		'data_type' => 'varchar',
		'character_maximum_length' => 40,
		'numeric_precision' => null,
		'numeric_scale' => null,
		'is_nullable' => 'NO',
		'column_default' => null,
	];

	/**
	 * @var array<int, string>
	 */
	private array $statements = [];

	private string $selectSql = '';

	public function testMySqlRestatesTheLengthOfAVarchar(): void {
		$this->release(row: self::ROW + ['column_type' => 'varchar(40)'], platform: new MariaDBPlatform(), isPostgres: false);

		$this->assertStringContainsString('column_type', $this->selectSql);
		$this->assertSame(['ALTER TABLE `t` MODIFY COLUMN `legacy` varchar(40) NULL'], $this->statements);
	}//end testMySqlRestatesTheLengthOfAVarchar()

	public function testPostgresDoesNotAskForAColumnItLacks(): void {
		$this->release(row: self::ROW, platform: new PostgreSQLPlatform(), isPostgres: true);

		$this->assertStringNotContainsString('column_type', $this->selectSql);
		$this->assertSame(['ALTER TABLE "t" ALTER COLUMN "legacy" DROP NOT NULL'], $this->statements);
	}//end testPostgresDoesNotAskForAColumnItLacks()

	private function release(array $row, object $platform, bool $isPostgres): void {
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('fetchAll')->willReturn([$row]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($platform);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($statement): IPreparedStatement {
				$this->selectSql = $sql;
				return $statement;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		$current = (new MagicTableColumnReader($db, $logger))->read('t', 't');
		$sync = new TableStructureSync(tableName: 't', fullTableName: 't', columns: new MagicColumnDrift(current: $current, required: []), isPostgres: $isPostgres);

		$released = (new MagicObsoleteColumns(new MagicDdlRunner($db, $logger), $logger))->release($sync);

		$this->assertSame(['legacy (obsolete)'], $released);
	}//end release()
}//end class
