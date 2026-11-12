<?php

/**
 * Tests for `occ openregister:declared-groups`: each row state, the
 * `--problems-only` filter, the totals line and when the warning is printed.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DeclaredGroupsCommand;
use OCA\OpenRegister\Service\Authorization\DeclaredGroupInventoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the command through CommandTester against a stubbed inventory.
 */
class DeclaredGroupsCommandTest extends TestCase {

	private const WARNING = 'A group with no members denies every caller.';

	/**
	 * Run the command against a given report.
	 *
	 * @param array<string, mixed> $report  What the inventory returns.
	 * @param array<string, mixed> $options Command input.
	 *
	 * @return array{0: int, 1: string} Exit code and display.
	 */
	private function runCommand(array $report, array $options=[]): array {
		$inventory = $this->createMock(DeclaredGroupInventoryInterface::class);
		$inventory->expects($this->once())->method('inventory')->willReturn($report);

		$tester = new CommandTester(new DeclaredGroupsCommand(inventory: $inventory));
		$code   = $tester->execute($options);

		return [$code, $tester->getDisplay()];
	}//end runCommand()

	/**
	 * One row per state the formatter distinguishes.
	 *
	 * @return array<string, mixed>
	 */
	private function mixedReport(): array {
		return [
			'groups'   => [
				['group' => 'gone', 'exists' => false, 'members' => null, 'grantsNobody' => true],
				['group' => 'ldap', 'exists' => true, 'members' => null, 'grantsNobody' => false],
				['group' => 'hollow', 'exists' => true, 'members' => 0, 'grantsNobody' => true],
				['group' => 'staff', 'exists' => true, 'members' => 5, 'grantsNobody' => false],
				['group' => 'crowd', 'exists' => true, 'members' => 12, 'grantsNobody' => false],
			],
			'declared' => 5,
			'missing'  => 1,
			'empty'    => 1,
			'unknown'  => 1,
		];
	}//end mixedReport()

	public function testNoDeclaredGroupsPrintsOnlyTheNotice(): void {
		[$code, $display] = $this->runCommand(
			report: ['groups' => [], 'declared' => 0, 'missing' => 0, 'empty' => 0, 'unknown' => 0]
		);

		$this->assertSame(0, $code);
		$this->assertSame("This instance declares no RBAC groups.\n", $display);
	}

	public function testEveryRowStateIsRenderedWithTotalsAndWarning(): void {
		[$code, $display] = $this->runCommand(report: $this->mixedReport());

		$expected = implode(
			"\n",
			[
				'  MISSING      gone  (declared, but no such Nextcloud group)',
				'  UNKNOWN      ldap  (backend cannot report a member count)',
				'  0 members    hollow  (grants nobody anything)',
				'  5  members   staff',
				'  12 members   crowd',
				'',
				'5 declared · 1 missing · 1 empty · 1 uncountable',
				'',
				'A group with no members denies every caller. RBAC resolves access by membership '
				.'test alone, so these rules read as configured and behave as denied until you add members.',
				'',
			]
		);

		$this->assertSame(0, $code);
		$this->assertSame($expected, $display);
	}

	public function testProblemsOnlyHidesRowsThatGrantSomebody(): void {
		[$code, $display] = $this->runCommand(report: $this->mixedReport(), options: ['--problems-only' => true]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('MISSING      gone', $display);
		$this->assertStringContainsString('0 members    hollow', $display);
		$this->assertStringNotContainsString('ldap', $display);
		$this->assertStringNotContainsString('staff', $display);
		$this->assertStringNotContainsString('crowd', $display);
		$this->assertStringContainsString('5 declared · 1 missing · 1 empty · 1 uncountable', $display);
	}

	public function testWithoutProblemsOnlyEveryRowIsShown(): void {
		[, $display] = $this->runCommand(report: $this->mixedReport());

		foreach (['gone', 'ldap', 'hollow', 'staff', 'crowd'] as $group) {
			$this->assertStringContainsString($group, $display);
		}
	}

	public function testOnlyMissingGroupsStillPrintTheWarning(): void {
		[$code, $display] = $this->runCommand(
			report: [
				'groups'   => [['group' => 'gone', 'exists' => false, 'members' => null, 'grantsNobody' => true]],
				'declared' => 1,
				'missing'  => 1,
				'empty'    => 0,
				'unknown'  => 0,
			]
		);

		$this->assertSame(0, $code);
		$this->assertStringContainsString(self::WARNING, $display);
	}

	public function testOnlyEmptyGroupsStillPrintTheWarning(): void {
		[$code, $display] = $this->runCommand(
			report: [
				'groups'   => [['group' => 'hollow', 'exists' => true, 'members' => 0, 'grantsNobody' => true]],
				'declared' => 1,
				'missing'  => 0,
				'empty'    => 1,
				'unknown'  => 0,
			]
		);

		$this->assertSame(0, $code);
		$this->assertStringContainsString(self::WARNING, $display);
	}

	public function testPopulatedOrUncountableGroupsPrintNoWarning(): void {
		[$code, $display] = $this->runCommand(
			report: [
				'groups'   => [
					['group' => 'staff', 'exists' => true, 'members' => 3, 'grantsNobody' => false],
					['group' => 'ldap', 'exists' => true, 'members' => null, 'grantsNobody' => false],
				],
				'declared' => 2,
				'missing'  => 0,
				'empty'    => 0,
				'unknown'  => 1,
			]
		);

		$this->assertSame(0, $code);
		$this->assertSame(
			"  3  members   staff\n  UNKNOWN      ldap  (backend cannot report a member count)\n\n"
			."2 declared · 0 missing · 0 empty · 1 uncountable\n",
			$display
		);
	}

	public function testProblemsOnlyWithNoProblemsPrintsOnlyTotals(): void {
		[$code, $display] = $this->runCommand(
			report: [
				'groups'   => [['group' => 'staff', 'exists' => true, 'members' => 3, 'grantsNobody' => false]],
				'declared' => 1,
				'missing'  => 0,
				'empty'    => 0,
				'unknown'  => 0,
			],
			options: ['--problems-only' => true]
		);

		$this->assertSame(0, $code);
		$this->assertSame("\n1 declared · 0 missing · 0 empty · 0 uncountable\n", $display);
	}

	public function testCommandIsRegisteredUnderItsName(): void {
		$command = new DeclaredGroupsCommand(inventory: $this->createMock(DeclaredGroupInventoryInterface::class));

		$this->assertSame('openregister:declared-groups', $command->getName());
		$this->assertTrue($command->getDefinition()->hasOption('problems-only'));
		$this->assertFalse($command->getDefinition()->getOption('problems-only')->acceptValue());
	}
}//end class
