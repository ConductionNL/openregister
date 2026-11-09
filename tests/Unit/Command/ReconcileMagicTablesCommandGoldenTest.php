<?php

/**
 * Golden test: the schema-column pass of `openregister:reconcile-magic-tables`.
 *
 * Pins, on fully mocked mappers, the exact display, exit code and repair calls
 * of the per-schema drift pass: no drift, a dry run, a successful and a failed
 * apply, a schema whose inspection throws, the register filter, and the
 * metadata columns that the metadata pass already counted.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The per-schema drift pass, end to end over mocks.
 *
 * @covers \OCA\OpenRegister\Command\ReconcileMagicTablesCommand
 */
class ReconcileMagicTablesCommandGoldenTest extends TestCase {

	private MagicMapperInterface&MockObject $magic;

	private RegisterMapperInterface&MockObject $registers;

	/**
	 * Two registers: r1 holds schema 11 (drifted) and 12 (clean), r2 holds 21 (throws).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->magic = $this->createMock(MagicMapperInterface::class);
		$this->registers = $this->createMock(RegisterMapperInterface::class);

		$this->magic->method('listMagicTableNames')->willReturn([]);
		$this->magic->method('getMetadataColumnDefinitions')->willReturn(['_status' => []]);
		$this->registers->method('findAll')->willReturn(['not a register', $this->register(1), $this->register(2)]);
		$this->registers->method('getSchemasByRegisterId')->willReturnCallback(
			fn (int $registerId): array => match ($registerId) {
				1 => [$this->schema(11, 'zaak'), 'not a schema', $this->schema(12, 'besluit')],
				2 => [$this->schema(21, 'kapot')],
			}
		);
		$this->magic->method('getTableNameForRegisterSchema')->willReturnCallback(
			static function (Register $register, Schema $schema): string {
				if ($schema->getId() === 21) {
					throw new RuntimeException('no table');
				}

				return 't' . $schema->getId();
			}
		);
		$this->magic->method('getExistingTableColumns')->willReturnCallback(static fn (string $t): array => [$t => true]);
		$this->magic->method('buildTableColumnsFromSchema')->willReturn([]);
		$this->magic->method('findMissingColumns')->willReturnCallback(
			static fn (array $current): array => match (array_key_first($current)) {
				't11' => ['title' => [], '_status' => [], 'size' => []],
				default => ['_status' => []],
			}
		);
		$this->magic->method('findDecimalColumnsNeedingWiden')->willReturnCallback(
			static fn (array $current): array => (array_key_first($current) === 't11' ? ['amount'] : [])
		);

	}//end setUp()

	/**
	 * A dry run reports every drifted schema and repairs nothing.
	 *
	 * @return void
	 */
	public function testDryRunReportsTheDriftAndTheSkip(): void {
		$this->magic->expects($this->never())->method('ensureTableForRegisterSchema');

		$tester = $this->tester();
		$exit = $tester->execute([]);

		$this->assertSame(0, $exit);
		$this->assertSame(
			"r1 schema=zaak(#11) missing 2: title, size\n"
			. "r1 schema=zaak(#11) number columns to widen 1: amount\n"
			. "skip r2/kapot (21): no table\n"
			. "1 table(s), 3 column(s) would be repaired. Re-run with --apply.\n",
			$tester->getDisplay()
		);

	}//end testDryRunReportsTheDriftAndTheSkip()

	/**
	 * A successful apply repairs and widens the drifted table only.
	 *
	 * @return void
	 */
	public function testApplyRepairsTheDriftedTable(): void {
		$this->magic->expects($this->once())->method('ensureTableForRegisterSchema')->willReturn(true);
		$this->magic->expects($this->once())->method('widenDecimalColumns')->with('t11', ['t11' => true], [])->willReturn([]);

		$tester = $this->tester();
		$exit = $tester->execute(['--apply' => true]);

		$this->assertSame(0, $exit);
		$this->assertStringEndsWith("Repaired 1/1 table(s); 0 failed.\n", $tester->getDisplay());

	}//end testApplyRepairsTheDriftedTable()

	/**
	 * A failing repair is reported and turns the exit code to 1.
	 *
	 * @return void
	 */
	public function testAFailedRepairExitsOne(): void {
		$this->magic->method('ensureTableForRegisterSchema')->willThrowException(new RuntimeException('ddl refused'));

		$tester = $this->tester();
		$exit = $tester->execute(['--apply' => true]);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("  repair failed: ddl refused\n", $tester->getDisplay());
		$this->assertStringEndsWith("Repaired 0/1 table(s); 1 failed.\n", $tester->getDisplay());

	}//end testAFailedRepairExitsOne()

	/**
	 * The register filter limits the pass to one register.
	 *
	 * @return void
	 */
	public function testTheRegisterFilterSkipsOtherRegisters(): void {
		$tester = $this->tester();
		$exit = $tester->execute(['--register' => '2']);

		$this->assertSame(0, $exit);
		$this->assertSame(
			"skip r2/kapot (21): no table\nNo magic-table column drift found.\n",
			$tester->getDisplay()
		);

	}//end testTheRegisterFilterSkipsOtherRegisters()

	/**
	 * The command under test.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(): CommandTester {
		return new CommandTester(
			new ReconcileMagicTablesCommand(registerMapper: $this->registers, magicMapper: $this->magic)
		);

	}//end tester()

	/**
	 * A register with an id.
	 *
	 * @param int $id The id.
	 *
	 * @return Register The register.
	 */
	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);

		return $register;
	}//end register()

	/**
	 * A schema with an id and slug.
	 *
	 * @param int $id The id.
	 * @param string $slug The slug.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, string $slug): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);

		return $schema;
	}//end schema()

}//end class
