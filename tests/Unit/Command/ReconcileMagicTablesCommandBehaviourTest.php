<?php

/**
 * ReconcileMagicTablesCommand walks the registers and schemas it is asked to,
 * skips what it cannot inspect, and adds both passes into one summary.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ReconcileMagicTablesCommand;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Behaviour of the reconcile command's register walk and metadata pass.
 */
class ReconcileMagicTablesCommandBehaviourTest extends TestCase {

	/** @var RegisterMapperInterface&MockObject */
	private RegisterMapperInterface $registerMapper;

	/** @var MagicMapperInterface&MockObject */
	private MagicMapperInterface $magicMapper;

	/** @var list<int> */
	private array $schemaLookups = [];

	protected function setUp(): void {
		$this->registerMapper = $this->createMock(RegisterMapperInterface::class);
		$this->magicMapper    = $this->createMock(MagicMapperInterface::class);
		$this->magicMapper->method('getMetadataColumnDefinitions')->willReturn(['_archived' => [], '_status' => []]);
		$this->magicMapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			static fn (Register $register, Schema $schema): string => 'openregister_table_'.$register->getId().'_'.$schema->getId()
		);
		$this->magicMapper->method('getExistingTableColumns')->willReturn(['id' => []]);
		$this->magicMapper->method('buildTableColumnsFromSchema')->willReturn(['id' => [], 'title' => []]);
		$this->magicMapper->method('findDecimalColumnsNeedingWiden')->willReturn([]);
	}

	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}

	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		return $schema;
	}

	/**
	 * Wire two registers (1 and 2) plus a non-register row, each register with one schema plus a non-schema row.
	 *
	 * @return void
	 */
	private function wireRegisters(): void {
		$this->registerMapper->method('findAll')->willReturn([$this->register(1), new stdClass(), $this->register(2)]);
		$this->registerMapper->method('getSchemasByRegisterId')->willReturnCallback(
			function (int $registerId): array {
				$this->schemaLookups[] = $registerId;
				return [new stdClass(), $this->schema(10 + $registerId, 'schema'.$registerId)];
			}
		);
	}

	private function tester(): CommandTester {
		return new CommandTester(new ReconcileMagicTablesCommand($this->registerMapper, $this->magicMapper));
	}

	public function testDryRunWalksEverySchemaOfEveryRegisterAndAddsTheCounts(): void {
		$this->wireRegisters();
		$this->magicMapper->method('listMagicTableNames')->willReturn([]);
		// "_archived" is a metadata column and must not be counted by the schema pass.
		$this->magicMapper->method('findMissingColumns')->willReturn(['title' => [], '_archived' => []]);
		$this->magicMapper->expects($this->never())->method('ensureTableForRegisterSchema');

		$tester = $this->tester();
		$exit   = $tester->execute([]);
		$display = $tester->getDisplay();

		$this->assertSame(0, $exit);
		$this->assertSame([1, 2], $this->schemaLookups);
		$this->assertStringContainsString('r1 schema=schema1(#11) missing 1: title', $display);
		$this->assertStringContainsString('r2 schema=schema2(#12) missing 1: title', $display);
		$this->assertStringContainsString('2 table(s), 2 column(s) would be repaired. Re-run with --apply.', $display);
	}

	public function testRegisterFilterLimitsBothPassesToThatRegister(): void {
		$this->wireRegisters();
		$this->magicMapper->method('listMagicTableNames')->willReturn(
			['openregister_table_1_11', 'openregister_table_2_12', 'not_a_magic_table']
		);
		$inspected = [];
		$this->magicMapper->method('inspectMetadataColumns')->willReturnCallback(
			function (string $tableName) use (&$inspected): array {
				$inspected[] = $tableName;
				return ['_archived' => []];
			}
		);
		$this->magicMapper->method('findMissingColumns')->willReturn([]);

		$tester = $this->tester();
		$exit   = $tester->execute(['--register' => '2']);
		$display = $tester->getDisplay();

		$this->assertSame(0, $exit);
		$this->assertSame(['openregister_table_2_12'], $inspected);
		$this->assertSame([2], $this->schemaLookups);
		$this->assertStringContainsString('openregister_table_2_12 metadata missing 1: _archived', $display);
		$this->assertStringNotContainsString('openregister_table_1_11', $display);
		$this->assertStringContainsString('1 table(s), 1 column(s) would be repaired.', $display);
	}

	public function testUninspectableTableIsSkippedAndTheRunGoesOn(): void {
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->magicMapper->method('listMagicTableNames')->willReturn(['openregister_table_1_1', 'openregister_table_1_2']);
		$this->magicMapper->method('inspectMetadataColumns')->willReturnCallback(
			static function (string $tableName): array {
				if ($tableName === 'openregister_table_1_1') {
					throw new RuntimeException('table vanished');
				}

				return [];
			}
		);

		$tester = $this->tester();
		$exit   = $tester->execute([]);
		$display = $tester->getDisplay();

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('skip openregister_table_1_1: table vanished', $display);
		$this->assertStringContainsString('No magic-table column drift found.', $display);
	}

	public function testFailedMetadataHealPrintsEachColumnAndExitsOne(): void {
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->magicMapper->method('listMagicTableNames')->willReturn(['openregister_table_3_4']);
		$this->magicMapper->method('inspectMetadataColumns')->willReturn(['_archived' => [], '_status' => []]);
		$this->magicMapper->expects($this->once())->method('healMetadataColumns')->with('openregister_table_3_4')
			->willReturn(['added' => [], 'failed' => ['_archived' => 'locked', '_status' => 'denied']]);

		$tester = $this->tester();
		$exit   = $tester->execute(['--apply' => true]);
		$display = $tester->getDisplay();

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('  _archived: locked', $display);
		$this->assertStringContainsString('  _status: denied', $display);
		$this->assertStringContainsString('Repaired 0/1 table(s); 1 failed.', $display);
	}

	public function testSuccessfulMetadataHealExitsZero(): void {
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->magicMapper->method('listMagicTableNames')->willReturn(['openregister_table_3_4']);
		$this->magicMapper->method('inspectMetadataColumns')->willReturn(['_archived' => []]);
		$this->magicMapper->expects($this->once())->method('healMetadataColumns')
			->willReturn(['added' => ['_archived'], 'failed' => []]);

		$tester = $this->tester();
		$exit   = $tester->execute(['--apply' => true]);

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('Repaired 1/1 table(s); 0 failed.', $tester->getDisplay());
	}
}
