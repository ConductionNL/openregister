<?php

/**
 * RelinkRegisterSchemasCommandTest.
 *
 * The command is dry-run by default; these tests pin that nothing is applied
 * without --write, what the report says per evidence kind, and the exact
 * apply() calls made when --write is given.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\RelinkRegisterSchemasCommand;
use OCA\OpenRegister\Service\RegisterSchemaLinkageRepairServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the empty report, dry run, --write, --register and evidence rendering.
 */
class RelinkRegisterSchemasCommandTest extends TestCase {
	private RegisterSchemaLinkageRepairServiceInterface&MockObject $repair;

	private CommandTester $tester;

	protected function setUp(): void {
		$this->repair = $this->createMock(RegisterSchemaLinkageRepairServiceInterface::class);
		$this->tester = new CommandTester(new RelinkRegisterSchemasCommand($this->repair));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function report(): array {
		return [
			[
				'registerId'   => 7,
				'registerSlug' => 'zaken',
				'currentIds'   => [1, 2],
				'recoverable'  => [10 => 5, 11 => 0, 12 => -1],
			],
			[
				'registerId'  => 9,
				'currentIds'  => [],
				'recoverable' => [20 => 1],
			],
		];
	}

	public function testNameIsRelinkSchemas(): void {
		$command = new RelinkRegisterSchemasCommand($this->repair);
		$this->assertSame('openregister:registers:relink-schemas', $command->getName());
		$this->assertTrue($command->getDefinition()->hasOption('write'));
		$this->assertTrue($command->getDefinition()->hasOption('register'));
	}

	public function testEmptyReportSaysNothingToDoAndNeverApplies(): void {
		$this->repair->expects($this->once())->method('inspect')->with(null)->willReturn([]);
		$this->repair->expects($this->never())->method('apply');

		$exit = $this->tester->execute(['--write' => true]);

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('No register has recoverable schema linkage. Nothing to do.', $this->tester->getDisplay());
		$this->assertStringNotContainsString('changed', $this->tester->getDisplay());
	}

	public function testRegisterOptionIsCastToIntAndPassedToInspect(): void {
		$this->repair->expects($this->once())->method('inspect')->with(42)->willReturn([]);

		$exit = $this->tester->execute(['--register' => '42']);

		$this->assertSame(Command::SUCCESS, $exit);
	}

	public function testDryRunRendersEvidenceAndAppliesNothing(): void {
		$this->repair->method('inspect')->willReturn($this->report());
		$this->repair->expects($this->never())->method('apply');

		$exit    = $this->tester->execute([]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('2 register(s) have recoverable schema linkage:', $display);
		$this->assertStringContainsString('register 7 (zaken) — currently [1, 2]', $display);
		$this->assertStringContainsString('register 9 (?) — currently []', $display);
		$this->assertStringContainsString('+ schema 10  (5 row(s))', $display);
		$this->assertStringContainsString('+ schema 11  (table exists but is empty)', $display);
		$this->assertStringContainsString('+ schema 12  (row count unavailable)', $display);
		$this->assertStringContainsString('+ schema 20  (1 row(s))', $display);
		$this->assertStringContainsString('DRY RUN — nothing was changed. Re-run with --write to apply.', $display);
		$this->assertStringNotContainsString('repaired', $display);
	}

	public function testWriteAppliesEachRegisterWithRecoverableSchemaIds(): void {
		$this->repair->method('inspect')->willReturn($this->report());
		$calls = [];
		$this->repair->expects($this->exactly(2))->method('apply')->willReturnCallback(
			function (int $registerId, array $schemaIds) use (&$calls): array {
				$calls[] = [$registerId, $schemaIds];
				return $registerId === 7 ? [1, 2, 10, 11, 12] : [20];
			}
		);

		$exit    = $this->tester->execute(['--write' => true]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertSame([[7, [10, 11, 12]], [9, [20]]], $calls);
		$this->assertStringContainsString('repaired register 7 -> schemas [1, 2, 10, 11, 12]', $display);
		$this->assertStringContainsString('repaired register 9 -> schemas [20]', $display);
		$this->assertStringContainsString('2 register(s) changed.', $display);
		$this->assertStringNotContainsString('DRY RUN', $display);
	}
}
