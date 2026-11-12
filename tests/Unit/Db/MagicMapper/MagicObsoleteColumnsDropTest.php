<?php

/**
 * Dropping a duplicate column uses SQL every supported database accepts.
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

use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner;
use OCA\OpenRegister\Db\MagicMapper\MagicObsoleteColumns;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicObsoleteColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicObsoleteColumnsDropTest extends TestCase {
	public function testMySqlDropsWithoutIfExists(): void {
		$this->assertSame(['ALTER TABLE `t` DROP COLUMN `firstName`'], $this->dropStatements(isPostgres: false));
	}//end testMySqlDropsWithoutIfExists()

	public function testPostgresDropsWithoutIfExists(): void {
		$this->assertSame(['ALTER TABLE "t" DROP COLUMN "firstName"'], $this->dropStatements(isPostgres: true));
	}//end testPostgresDropsWithoutIfExists()

	/**
	 * @return array<int, string> The statements run for a camelCase duplicate of `first_name`.
	 */
	private function dropStatements(bool $isPostgres): array {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$columns = new MagicColumnDrift(
			required: ['firstName' => ['name' => 'first_name', 'type' => 'string']],
			current: ['firstName' => ['name' => 'firstName', 'type' => 'text'], 'first_name' => ['name' => 'first_name', 'type' => 'text']]
		);
		$sync = new TableStructureSync(tableName: 't', fullTableName: 't', columns: $columns, isPostgres: $isPostgres);

		$dropped = (new MagicObsoleteColumns(new MagicDdlRunner($db, $logger), $logger))->dropDuplicates($sync);

		$this->assertSame(['firstName'], $dropped);

		return $statements;
	}//end dropStatements()
}//end class
