<?php

/**
 * MagicTableAlterer runs every structure step on a live magic table and reports each change.
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
use OCP\IConfig;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift;
use OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes;
use OCA\OpenRegister\Db\MagicMapper\MagicTableAlterer;
use OCA\OpenRegister\Db\MagicMapper\TableStructureSync;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicTableAlterer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnAdder
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnDrift
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNullability
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnRetyper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlFailure
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDdlRunner
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicDecimalWidener
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNullabilityChange
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumn
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberColumnSizes
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicNumberWidening
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicObsoleteColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\TableStructureSync
 */
class MagicTableAltererTest extends TestCase {

	/**
	 * Statements the connection received.
	 *
	 * @var array<int, string>
	 */
	private array $statements = [];

	/**
	 * The logger behind the alterer.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * An alterer that records every DDL statement.
	 *
	 * @return MagicTableAlterer
	 */
	private function alterer(): MagicTableAlterer {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		return new MagicTableAlterer(new MagicTableSql($db, $this->createMock(IConfig::class), $this->logger), new MagicNumberColumnSizes($db, 'oc_'));
	}//end alterer()

	/**
	 * A sync on a PostgreSQL table where every step has something to do.
	 *
	 * @return TableStructureSync
	 */
	private static function driftedSync(): TableStructureSync {
		$current = [
			'_id' => ['type' => 'bigint', 'nullable' => false],
			'title' => ['type' => 'text', 'nullable' => false],
			'summary' => ['type' => 'text', 'nullable' => true],
			'legacy' => ['type' => 'text', 'nullable' => false],
			'myField' => ['type' => 'text', 'nullable' => true],
			'my_field' => ['type' => 'text', 'nullable' => true],
			'doc' => ['type' => 'jsonb', 'nullable' => true],
			'ref' => ['type' => 'json', 'nullable' => true],
			'amount' => ['type' => 'decimal', 'precision' => 10, 'scale' => 2, 'nullable' => true],
		];
		$required = [
			'title' => ['name' => 'title', 'type' => 'text', 'nullable' => true],
			'summary' => ['name' => 'summary', 'type' => 'text', 'nullable' => false],
			'my_field' => ['name' => 'my_field', 'type' => 'text'],
			'doc' => ['name' => 'doc', 'type' => 'json_ordered'],
			'ref' => ['name' => 'ref', 'type' => 'string', 'length' => 255],
			'amount' => ['name' => 'amount', 'type' => 'decimal', 'precision' => 20, 'scale' => 4],
			'added' => ['name' => 'added', 'type' => 'text'],
		];

		return new TableStructureSync('openregister_table_1_2', 'oc_openregister_table_1_2', new MagicColumnDrift($current, $required), true);
	}//end driftedSync()

	/**
	 * Every step reports its columns under its own key, and the statements run in step order.
	 *
	 * @return void
	 */
	public function testUpdateReportsEveryStepsChanges(): void {
		$alterer = $this->alterer();
		$changes = $alterer->update(self::driftedSync());

		$this->assertSame(
			[
				'columnsAdded' => ['added'],
				'columnsDeRequired' => ['title', 'legacy (obsolete)'],
				'columnsReRequired' => ['summary'],
				'columnsDropped' => ['myField'],
				'columnsRetyped' => ['doc', 'amount'],
			],
			$changes
		);
		$this->assertStringContainsString('ADD COLUMN "added"', $this->statements[0]);
		$this->assertStringContainsString('ALTER COLUMN "title" DROP NOT NULL', $this->statements[1]);
		$this->assertStringContainsString('ALTER COLUMN "summary" SET NOT NULL', $this->statements[2]);
		$this->assertStringContainsString('DROP COLUMN "myField"', $this->statements[3]);
		$this->assertStringContainsString('"legacy"', $this->statements[4]);
		$this->assertStringContainsString('ALTER COLUMN "doc" TYPE JSON', $this->statements[5]);
		$this->assertStringContainsString('"amount"', $this->statements[6]);
		$this->assertStringContainsString('DECIMAL(20,4)', $this->statements[6]);
		$this->assertCount(7, $this->statements);
	}//end testUpdateReportsEveryStepsChanges()

	/**
	 * The update is logged once at info level with the table and its changes.
	 *
	 * @return void
	 */
	public function testUpdateIsLoggedWithTheChanges(): void {
		$alterer = $this->alterer();
		$sync = new TableStructureSync('t', 'oc_t', new MagicColumnDrift(['a' => ['type' => 'text']], ['a' => ['name' => 'a', 'type' => 'text']]), false);
		$this->logger->expects($this->once())->method('info')->with(
			'[MagicMapper] Successfully updated table structure',
			[
				'tableName' => 't',
				'columnsAdded' => [],
				'columnsDeRequired' => [],
				'columnsReRequired' => [],
				'columnsDropped' => [],
				'columnsRetyped' => [],
			]
		);

		$alterer->update($sync);

		$this->assertSame([], $this->statements);
	}//end testUpdateIsLoggedWithTheChanges()

	/**
	 * Relation columns stored as JSON are retyped to VARCHAR on PostgreSQL.
	 *
	 * @return void
	 */
	public function testRetypeRelationsRetypesJsonRelationColumns(): void {
		$this->assertSame(['ref'], $this->alterer()->retypeRelations(self::driftedSync()));
		$this->assertCount(1, $this->statements);
		$this->assertStringContainsString('ALTER COLUMN "ref" TYPE VARCHAR(255)', $this->statements[0]);
	}//end testRetypeRelationsRetypesJsonRelationColumns()

	/**
	 * The widener it hands out widens the same table's number columns.
	 *
	 * @return void
	 */
	public function testTheWidenerWidensNumberColumns(): void {
		$widenings = $this->alterer()->widener()->widenings(self::driftedSync()->columns);

		$this->assertSame(['amount'], array_keys($widenings));
	}//end testTheWidenerWidensNumberColumns()
}//end class
