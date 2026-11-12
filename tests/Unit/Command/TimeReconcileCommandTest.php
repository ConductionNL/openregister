<?php

/**
 * Characterisation: what occ openregister:time:reconcile passes to the service and prints.
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
 * @spec openspec/specs/integration-time-tracker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\TimeReconcileCommand;
use OCA\OpenRegister\Service\TimeTrackerLinkServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the scope and dry-run labels, the stats line and the exit code.
 */
class TimeReconcileCommandTest extends TestCase {

	/**
	 * Run the command over a service answering $stats, expecting the given arguments.
	 *
	 * @param array<string, mixed> $input   The console input.
	 * @param string|null          $object  The object uuid the service must receive.
	 * @param bool                 $dryRun  The dry-run flag the service must receive.
	 * @param array<string, int>   $stats   What the service answers.
	 *
	 * @return array{0: int, 1: string} The exit code and the display.
	 */
	private function reconcile(array $input, ?string $object, bool $dryRun, array $stats): array {
		$service = $this->createMock(TimeTrackerLinkServiceInterface::class);
		$service->expects($this->once())->method('reconcileAllLinks')->with($object, $dryRun)->willReturn($stats);

		$tester = new CommandTester(new TimeReconcileCommand($service));
		$code = $tester->execute($input);

		return [$code, $tester->getDisplay()];
	}//end reconcile()

	/**
	 * Every link row, written for real, without errors.
	 *
	 * @return void
	 */
	public function testAllLinkRowsAreReconciled(): void {
		$this->assertSame(
			[Command::SUCCESS, "Reconciling all link rows\n walked=5 refreshed=2 missing=1 errors=0\n"],
			$this->reconcile([], null, false, ['walked' => 5, 'refreshed' => 2, 'missing' => 1, 'errors' => 0])
		);
	}//end testAllLinkRowsAreReconciled()

	/**
	 * One object, as a dry run; an empty --object means every row.
	 *
	 * @return void
	 */
	public function testOneObjectAsADryRun(): void {
		$this->assertSame(
			[Command::SUCCESS, "Reconciling object=u-1 (dry run)\n walked=1 refreshed=0 missing=0 errors=0\n"],
			$this->reconcile(['--object' => 'u-1', '--dry-run' => true], 'u-1', true, ['walked' => 1, 'refreshed' => 0, 'missing' => 0, 'errors' => 0])
		);
		$this->assertSame(
			[Command::SUCCESS, "Reconciling all link rows (dry run)\n walked=0 refreshed=0 missing=0 errors=0\n"],
			$this->reconcile(['--object' => '', '--dry-run' => true], null, true, ['walked' => 0, 'refreshed' => 0, 'missing' => 0, 'errors' => 0])
		);
	}//end testOneObjectAsADryRun()

	/**
	 * Any error fails the run.
	 *
	 * @return void
	 */
	public function testAnErrorFailsTheRun(): void {
		$this->assertSame(
			[Command::FAILURE, "Reconciling all link rows\n walked=3 refreshed=1 missing=0 errors=2\n"],
			$this->reconcile([], null, false, ['walked' => 3, 'refreshed' => 1, 'missing' => 0, 'errors' => 2])
		);
	}//end testAnErrorFailsTheRun()
}//end class
