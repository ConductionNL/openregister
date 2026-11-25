<?php

/**
 * Tests for occ openregister:audit:seal-backlog.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Command;

use OCA\OpenRegister\Command\SealAuditBacklogCommand;
use OCA\OpenRegister\Service\Audit\AuditSealBacklogInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the command against a mocked driver.
 */
#[CoversClass(SealAuditBacklogCommand::class)]
class SealAuditBacklogCommandTest extends TestCase {

	/**
	 * Mocked driver.
	 *
	 * @var AuditSealBacklogInterface&MockObject
	 */
	private AuditSealBacklogInterface&MockObject $backlog;

	/**
	 * Tester wrapping the command.
	 *
	 * @var CommandTester
	 */
	private CommandTester $tester;

	/**
	 * Wire the command.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->backlog = $this->createMock(AuditSealBacklogInterface::class);
		$this->tester = new CommandTester(new SealAuditBacklogCommand($this->backlog));
	}//end setUp()

	/**
	 * A drain result.
	 *
	 * @param string $stoppedBy Why it stopped.
	 * @param int    $behind    Unsealed rows behind the newest link.
	 *
	 * @return array{rowsSealed: int, windowsProcessed: int, windowsAbandoned: int, stoppedBy: string, unsealedBehindChain: int}
	 */
	private static function drained(string $stoppedBy, int $behind): array {
		$abandoned = 0;
		if ($stoppedBy === 'lock') {
			$abandoned = 1;
		}

		return [
			'rowsSealed' => 2500,
			'windowsProcessed' => 2,
			'windowsAbandoned' => $abandoned,
			'stoppedBy' => $stoppedBy,
			'unsealedBehindChain' => $behind,
		];
	}//end result()

	/**
	 * Without --limit it drains everything, without a time cap, printing each window.
	 *
	 * @return void
	 */
	public function testItDrainsEverythingAndPrintsEachWindow(): void {
		$this->backlog->expects($this->once())->method('drain')
			->with(PHP_INT_MAX, 0, $this->isType('callable'))
			->willReturnCallback(
				static function (int $maxRows, int $maxSeconds, callable $onWindow): array {
					$onWindow(['rowsSealed' => 2000, 'windowsProcessed' => 1, 'windowsAbandoned' => 0, 'stoppedBy' => 'drained']);
					$onWindow(['rowsSealed' => 2500, 'windowsProcessed' => 2, 'windowsAbandoned' => 0, 'stoppedBy' => 'drained']);
					return self::drained('drained', 0);
				}
			);

		$exit = $this->tester->execute([]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('window 1: 2000 row(s) sealed so far', $display);
		$this->assertStringContainsString('window 2: 2500 row(s) sealed so far', $display);
		$this->assertStringContainsString('Sealed 2500 row(s) in 2 window(s); stopped: drained.', $display);
		$this->assertStringNotContainsString('rechain-audit-trail', $display);
	}//end testItDrainsEverythingAndPrintsEachWindow()

	/**
	 * --limit caps the rows of the run.
	 *
	 * @return void
	 */
	public function testTheLimitCapsTheRun(): void {
		$this->backlog->expects($this->once())->method('drain')
			->with(2500, 0, $this->isType('callable'))
			->willReturn(self::drained('row-cap', 0));

		$this->assertSame(Command::SUCCESS, $this->tester->execute(['--limit' => '2500']));
		$this->assertStringContainsString('stopped: row-cap', $this->tester->getDisplay());
	}//end testTheLimitCapsTheRun()

	/**
	 * A limit that is not a positive whole number is refused before anything is sealed.
	 *
	 * @return void
	 */
	public function testABadLimitIsRefused(): void {
		$this->backlog->expects($this->never())->method('drain');

		foreach (['0', '-3', 'abc', '1.5'] as $limit) {
			$this->assertSame(Command::FAILURE, $this->tester->execute(['--limit' => $limit]), $limit);
			$this->assertStringContainsString('--limit must be a positive whole number', $this->tester->getDisplay());
		}
	}//end testABadLimitIsRefused()

	/**
	 * Rows behind the newest link are named, with the repair that can seal them.
	 *
	 * @return void
	 */
	public function testRowsBehindTheChainAreNamedWithTheirRemedy(): void {
		$this->backlog->method('drain')->willReturn(self::drained('drained', 42));

		$this->assertSame(Command::SUCCESS, $this->tester->execute([]));
		$display = $this->tester->getDisplay();
		$this->assertStringContainsString('42 unsealed row(s) lie before the newest sealed row', $display);
		$this->assertStringContainsString('occ openregister:rechain-audit-trail', $display);
	}//end testRowsBehindTheChainAreNamedWithTheirRemedy()

	/**
	 * A window abandoned on the seal lock fails the run, so a script sees it.
	 *
	 * @return void
	 */
	public function testABusyLockFailsTheRun(): void {
		$this->backlog->method('drain')->willReturn(self::drained('lock', 0));

		$this->assertSame(Command::FAILURE, $this->tester->execute([]));
		$this->assertStringContainsString('The seal lock was busy', $this->tester->getDisplay());
	}//end testABusyLockFailsTheRun()
}//end class
