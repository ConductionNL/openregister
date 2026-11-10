<?php

/**
 * Reconcile and the upgrade step see and heal metadata-column drift (14 October 2026).
 *
 * `occ openregister:tables:reconcile --apply` answered "No drift" while 38
 * magic tables lacked `_archived`: it walked only the schemas a register still
 * lists, and 37 of the 38 tables were not among them. These tests run the
 * command and the AddObjectStateColumns repair step against such a table in a
 * real SQLite database, with no register listing its schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-reconcile-reports-and-repairs-missing-metadata-columns-req-mth-003
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-the-upgrade-step-and-reconcile-share-the-healer-req-mth-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ReconcileMagicTablesCommand;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Repair\AddObjectStateColumns;
use OCA\OpenRegister\Tests\Unit\Db\MagicMapper\MagicMetadataColumnHealerTest;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs reconcile and the repair step against a drifted, orphaned SQLite table.
 */
class ReconcileMagicTablesMetadataTest extends TestCase {

	private PDO $pdo;

	private MagicMapper&MockObject $magicMapper;

	private int $columnReads = 0;

	protected function setUp(): void {
		if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
			$this->markTestSkipped('pdo_sqlite is not available.');
		}

		MagicMetadataColumnHealer::reset();

		$this->pdo = MagicMetadataColumnHealerTest::createDriftedTable(omit: MagicMetadataColumnHealerTest::DRIFTED);
		$this->magicMapper = MagicMetadataColumnHealerTest::wireMagicMapper(
			testCase: $this,
			pdo: $this->pdo,
			columnReads: $this->columnReads
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(false);
		$db->method('executeStatement')->willReturnCallback(fn (string $sql): int => (int)$this->pdo->exec($sql));

		// The command and the step reach the healer through MagicMapper, as in production.
		$healer = new MagicMetadataColumnHealer(
			db: $db,
			logger: $this->createMock(LoggerInterface::class),
			magicMapper: $this->magicMapper
		);
		$this->magicMapper->method('listMagicTableNames')->willReturn([MagicMetadataColumnHealerTest::TABLE]);
		$this->magicMapper->method('inspectMetadataColumns')->willReturnCallback(
			static fn (string $tableName): array => $healer->inspect(tableName: $tableName)
		);
		$this->magicMapper->method('healMetadataColumns')->willReturnCallback(
			static fn (string $tableName): array => $healer->heal(tableName: $tableName)
		);
	}//end setUp()

	protected function tearDown(): void {
		MagicMetadataColumnHealer::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * The command, with no register listing the table's schema.
	 *
	 * @return CommandTester The tester.
	 */
	private function command(): CommandTester {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([]);

		return new CommandTester(new ReconcileMagicTablesCommand(registerMapper: $registerMapper, magicMapper: $this->magicMapper));
	}//end command()

	/**
	 * The table's column names.
	 *
	 * @return string[] The names.
	 */
	private function columns(): array {
		return array_column(
			$this->pdo->query('PRAGMA table_info("oc_'.MagicMetadataColumnHealerTest::TABLE.'")')->fetchAll(PDO::FETCH_ASSOC),
			'name'
		);
	}//end columns()

	public function testDryRunReportsTheOrphanedTableAndChangesNothing(): void {
		$tester = $this->command();

		$exit = $tester->execute([]);
		$display = $tester->getDisplay();

		$this->assertSame(0, $exit);
		$this->assertStringContainsString(MagicMetadataColumnHealerTest::TABLE.' metadata missing 3: _archived, _frozen, _status', $display);
		$this->assertStringContainsString('Re-run with --apply', $display);
		$this->assertStringNotContainsString('No magic-table column drift found', $display);
		$this->assertNotContains('_archived', $this->columns());
	}//end testDryRunReportsTheOrphanedTableAndChangesNothing()

	public function testApplyAddsTheMissingColumnsAndASecondRunFindsNoDrift(): void {
		$tester = $this->command();

		$exit = $tester->execute(['--apply' => true]);

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('Repaired 1/1 table(s); 0 failed.', $tester->getDisplay());
		foreach (MagicMetadataColumnHealerTest::DRIFTED as $name) {
			$this->assertContains($name, $this->columns());
		}

		$again = $this->command();
		$this->assertSame(0, $again->execute([]));
		$this->assertStringContainsString('No magic-table column drift found', $again->getDisplay());
	}//end testApplyAddsTheMissingColumnsAndASecondRunFindsNoDrift()

	public function testRegisterFilterLeavesOtherRegistersTablesAlone(): void {
		$tester = $this->command();

		$tester->execute(['--apply' => true, '--register' => '9']);

		$this->assertStringContainsString('No magic-table column drift found', $tester->getDisplay());
		$this->assertNotContains('_archived', $this->columns());
	}//end testRegisterFilterLeavesOtherRegistersTablesAlone()

	public function testTheUpgradeStepHealsTheOrphanedTable(): void {
		$step = new AddObjectStateColumns(
			magicMapper: $this->magicMapper,
			logger: $this->createMock(LoggerInterface::class)
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('1 healed, 0 could not be'));

		$step->run($output);

		foreach (MagicMetadataColumnHealerTest::DRIFTED as $name) {
			$this->assertContains($name, $this->columns());
		}
	}//end testTheUpgradeStepHealsTheOrphanedTable()
}//end class
