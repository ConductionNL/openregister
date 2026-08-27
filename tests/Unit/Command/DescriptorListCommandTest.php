<?php

/**
 * DescriptorListCommandTest.
 *
 * The command exists because the condition it reports — a register descriptor
 * that never landed — is most likely on an instance whose admin UI may itself
 * depend on the broken thing. So the assertions here are about what it SAYS,
 * not merely that it exits zero.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/register-descriptor-admin/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DescriptorListCommand;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the inventory output, the problems filter, and the forced re-import.
 */
class DescriptorListCommandTest extends TestCase {
	private RegisterDescriptorService&MockObject $descriptors;

	private CommandTester $tester;

	protected function setUp(): void {
		$this->descriptors = $this->createMock(RegisterDescriptorService::class);
		$this->tester      = new CommandTester(new DescriptorListCommand($this->descriptors));
	}

	private function row(string $app, string $slug, string $state, ?string $installed): array {
		return [
			'appId'            => $app,
			'slug'             => $slug,
			'title'            => ucfirst($slug),
			'state'            => $state,
			'installedVersion' => $installed,
			'shippedVersion'   => '1.3.0',
			'descriptor'       => $slug . '_register.json',
		];
	}

	public function testItListsEveryRowAndCountsTheStates(): void {
		$this->descriptors->method('inventory')->willReturn(
			[
				$this->row('openregister', 'flows', RegisterDescriptorService::STATE_CURRENT, '1.3.0'),
				$this->row('openregister', 'ori', RegisterDescriptorService::STATE_ABSENT, null),
				$this->row('dossiq', 'dossiq', RegisterDescriptorService::STATE_BEHIND, '1.0.0'),
			]
		);

		$this->assertSame(0, $this->tester->execute([]));
		$output = $this->tester->getDisplay();

		$this->assertStringContainsString('flows', $output);
		$this->assertStringContainsString('ori', $output);
		// The tally is the line an operator reads first.
		$this->assertStringContainsString('3 declared', $output);
		$this->assertStringContainsString('1 ABSENT', $output);
	}

	/**
	 * 🔴 LISTING EXITS ZERO EVEN WITH ABSENT REGISTERS. An absent register is a
	 * state for an administrator to decide about, not a failure of the command —
	 * a non-zero exit here would turn a report into a broken cron job.
	 */
	public function testListingExitsZeroEvenWhenRegistersAreAbsent(): void {
		$this->descriptors->method('inventory')
			->willReturn([$this->row('openregister', 'ori', RegisterDescriptorService::STATE_ABSENT, null)]);

		$this->assertSame(0, $this->tester->execute([]));
	}

	public function testProblemsOnlyHidesCurrentRowsAndKeepsTheRest(): void {
		$this->descriptors->method('inventory')->willReturn(
			[
				$this->row('openregister', 'flows', RegisterDescriptorService::STATE_CURRENT, '1.3.0'),
				$this->row('openregister', 'ori', RegisterDescriptorService::STATE_ABSENT, null),
			]
		);

		$this->tester->execute(['--problems-only' => true]);
		$output = $this->tester->getDisplay();

		$this->assertStringNotContainsString(' flows ', $output);
		$this->assertStringContainsString('ori', $output);
		// The totals still describe the WHOLE instance, not the filtered view —
		// a filter that also changes the tally hides what it filtered.
		$this->assertStringContainsString('2 declared', $output);
	}

	public function testAnInstanceWithNoDescriptorsSaysSoRatherThanPrintingNothing(): void {
		$this->descriptors->method('inventory')->willReturn([]);

		$this->assertSame(0, $this->tester->execute([]));
		$this->assertStringContainsString('No installed app ships', $this->tester->getDisplay());
	}

	public function testImportRunsTheForcedReimportAndReportsIt(): void {
		$this->descriptors->expects($this->once())
			->method('reimport')
			->with('openregister', 'flows')
			->willReturn(['outcome' => 'imported', 'reason' => null]);

		$this->assertSame(0, $this->tester->execute(['--import' => 'flows', '--app' => 'openregister']));
		$this->assertStringContainsString('imported', $this->tester->getDisplay());
	}

	/**
	 * A FAILED re-import exits non-zero, unlike a listing: here the caller asked
	 * for something to happen and it did not.
	 */
	public function testAFailedImportExitsNonZeroAndPrintsTheReason(): void {
		$this->descriptors->method('reimport')
			->willReturn(['outcome' => 'failed', 'reason' => 'no descriptor declares "nope"']);

		$this->assertSame(1, $this->tester->execute(['--import' => 'nope', '--app' => 'openregister']));
		$this->assertStringContainsString('nope', $this->tester->getDisplay());
	}

	/**
	 * `--import` without `--app` is ambiguous: two apps may ship a register with
	 * the same slug. Refuse rather than guess which one was meant.
	 */
	public function testImportWithoutAnAppIsRefusedAndImportsNothing(): void {
		$this->descriptors->expects($this->never())->method('reimport');

		$this->assertSame(1, $this->tester->execute(['--import' => 'flows']));
		$this->assertStringContainsString('--app', $this->tester->getDisplay());
	}
}
