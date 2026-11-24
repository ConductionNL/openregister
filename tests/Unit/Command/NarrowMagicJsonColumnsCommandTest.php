<?php

/**
 * NarrowMagicJsonColumnsCommandTest: the console surface of `openregister:tables:narrow-json`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\NarrowMagicJsonColumnsCommand;
use OCA\OpenRegister\Db\MagicMapper\MagicJsonColumnNarrower;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Command\NarrowMagicJsonColumnsCommand
 *
 * @spec openspec/specs/magic-table-columns/spec.md#requirement-a-json-column-does-not-slow-every-schema-read-of-the-instance-req-mtc-003
 */
class NarrowMagicJsonColumnsCommandTest extends TestCase {

	public function testOffMariaDbItSaysThereIsNothingToNarrow(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(false);
		$narrower->expects($this->never())->method('pending');
		$narrower->expects($this->never())->method('narrowPending');

		$tester = new CommandTester(new NarrowMagicJsonColumnsCommand($narrower));

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertStringContainsString('nothing to narrow', $tester->getDisplay());
	}//end testOffMariaDbItSaysThereIsNothingToNarrow()

	public function testADryRunListsTheTablesWithTheirSizeAndChangesNothing(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(true);
		$narrower->method('pending')->willReturn(
			[
				'oc_openregister_table_1_2' => ['columns' => ['_files', '_relations'], 'bytes' => 3145728],
			]
		);
		$narrower->expects($this->never())->method('narrowPending');

		$tester = new CommandTester(new NarrowMagicJsonColumnsCommand($narrower));

		$this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('oc_openregister_table_1_2  2 column(s)  3.0 MB', $tester->getDisplay());
		$this->assertStringContainsString('1 table(s) hold JSON in LONGTEXT columns.', $tester->getDisplay());
	}//end testADryRunListsTheTablesWithTheirSizeAndChangesNothing()

	public function testItNarrowsEveryPendingTableWhateverItsSize(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(true);
		$limits = null;
		$narrower->expects($this->once())->method('narrowPending')->willReturnCallback(
			static function (...$given) use (&$limits): array {
				$limits = $given;
				return ['narrowed' => ['oc_openregister_table_1_2', 'oc_openregister_table_3_4'], 'failed' => [], 'left' => []];
			}
		);

		$tester = new CommandTester(new NarrowMagicJsonColumnsCommand($narrower));

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertSame([null, null], $limits, 'the size limit and time budget belong to the upgrade step only');
		$this->assertStringContainsString('Narrowed the JSON columns of 2 table(s); 0 could not be.', $tester->getDisplay());
	}//end testItNarrowsEveryPendingTableWhateverItsSize()

	public function testATableThatCouldNotBeNarrowedIsNamedAndFailsTheCommand(): void {
		$narrower = $this->createMock(MagicJsonColumnNarrower::class);
		$narrower->method('applies')->willReturn(true);
		$narrower->method('narrowPending')->willReturn(
			['narrowed' => [], 'failed' => ['oc_openregister_table_5_6' => 'Data too long for column'], 'left' => []]
		);

		$tester = new CommandTester(new NarrowMagicJsonColumnsCommand($narrower));

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('oc_openregister_table_5_6: left as it is: Data too long for column', $tester->getDisplay());
	}//end testATableThatCouldNotBeNarrowedIsNamedAndFailsTheCommand()
}//end class
