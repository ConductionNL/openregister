<?php

/**
 * Tests for the one-off audit-trail re-chain command.
 *
 * This command rewrites every stored audit hash, which is the single operation
 * the hash chain exists to make suspicious. Its safety therefore lives in its
 * interface, not only in the service it calls: it must refuse without consent,
 * it must not write at all under --dry-run, and it must report failure when the
 * chain is still broken afterwards rather than exiting 0 on a repair that did
 * not repair.
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
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace Unit\Command;

use OCA\OpenRegister\Command\RechainAuditTrailCommand;
use OCA\OpenRegister\Service\AuditHashService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves the command cannot rewrite hashes by accident.
 */
class RechainAuditTrailCommandTest extends TestCase {

	/**
	 * Mocked hash service.
	 *
	 * @var AuditHashService&MockObject
	 */
	private AuditHashService&MockObject $hashes;

	/**
	 * Tester wrapping the command under test.
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

		$this->hashes = $this->createMock(AuditHashService::class);

		$command = new RechainAuditTrailCommand($this->hashes);

		// occ supplies the HelperSet when it registers the command; a bare
		// CommandTester does not, and getHelper('question') throws without it.
		// Providing it here keeps the confirmation prompt — the command's whole
		// safety mechanism — under test rather than skipped.
		$command->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));

		$this->tester = new CommandTester($command);

	}//end setUp()

	/**
	 * A verification result shaped like verifyChain()'s.
	 *
	 * @param bool $valid Whether the chain verifies.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function verification(bool $valid): array {
		return [
			'valid' => $valid,
			'entriesVerified' => 10,
			'brokenAt' => ($valid === true ? null : 42),
			'skippedNullHashes' => 0,
		];
	}//end verification()

	/**
	 * --dry-run reports the state and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testDryRunNeverRechains(): void {
		$this->hashes->method('verifyChain')->willReturn($this->verification(false));
		$this->hashes->method('countUnsealed')->willReturn(7);
		$this->hashes->expects($this->never())->method('rechainAll');

		$this->assertSame(Command::SUCCESS, $this->tester->execute(['--dry-run' => true]));
		$this->assertStringContainsString('Dry run', $this->tester->getDisplay());

	}//end testDryRunNeverRechains()

	/**
	 * Declining the confirmation leaves the hashes alone.
	 *
	 * The prompt is the whole safety mechanism for an interactive operator, so
	 * "answered no" must mean nothing was written — not merely that the output
	 * said so.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testAnsweringNoRechainsNothing(): void {
		$this->hashes->method('verifyChain')->willReturn($this->verification(false));
		$this->hashes->method('countUnsealed')->willReturn(7);
		$this->hashes->expects($this->never())->method('rechainAll');

		$this->tester->setInputs(['no']);

		$this->assertSame(Command::SUCCESS, $this->tester->execute([]));
		$this->assertStringContainsString('Aborted', $this->tester->getDisplay());

	}//end testAnsweringNoRechainsNothing()

	/**
	 * --force skips the prompt and repairs, reporting success once valid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testForceRepairsAndReportsSuccess(): void {
		// Broken before, whole after — the shape a real repair produces.
		$this->hashes->method('verifyChain')
			->willReturnOnConsecutiveCalls($this->verification(false), $this->verification(true));
		$this->hashes->method('countUnsealed')->willReturn(0);
		$this->hashes->expects($this->once())->method('rechainAll')->willReturn(['rechained' => 313136]);

		$this->assertSame(Command::SUCCESS, $this->tester->execute(['--force' => true]));
		$this->assertStringContainsString('313136', $this->tester->getDisplay());

	}//end testForceRepairsAndReportsSuccess()

	/**
	 * A chain still broken after the repair exits FAILURE.
	 *
	 * Exiting 0 here would be the worst outcome available: an operator runs the
	 * repair, sees success, and walks away from a chain that still does not
	 * verify. The command must say the repair did not take.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/audit-hash-chain/spec.md
	 */
	public function testStillBrokenAfterwardsIsAFailure(): void {
		$this->hashes->method('verifyChain')->willReturn($this->verification(false));
		$this->hashes->method('countUnsealed')->willReturn(3);
		$this->hashes->method('rechainAll')->willReturn(['rechained' => 5]);

		$this->assertSame(Command::FAILURE, $this->tester->execute(['--force' => true]));
		$this->assertStringContainsString('do not treat this repair as complete', $this->tester->getDisplay());

	}//end testStillBrokenAfterwardsIsAFailure()
}//end class
