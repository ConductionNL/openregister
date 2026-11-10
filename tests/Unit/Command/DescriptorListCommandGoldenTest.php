<?php

/**
 * DescriptorListCommandGoldenTest.
 *
 * Pins the command's complete console output, exit code and option definition
 * byte for byte, so a restructuring of the command cannot change what an
 * operator reads. Written against the code before its refactor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Service\ShippedRegister;
use OCA\OpenRegister\Command\DescriptorListCommand;
use OCA\OpenRegister\Service\AppRegisterImportRunner;
use OCA\OpenRegister\Service\RegisterDescriptorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Golden output of every branch of the descriptor command.
 */
class DescriptorListCommandGoldenTest extends TestCase {

	/**
	 * The inventory service double.
	 *
	 * @var RegisterDescriptorService&MockObject
	 */
	private RegisterDescriptorService&MockObject $descriptors;

	/**
	 * Build the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->descriptors = $this->createMock(RegisterDescriptorService::class);
	}//end setUp()

	/**
	 * Run the command and return [exit code, display].
	 *
	 * @param array<string, mixed> $input The console input.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function runCommand(array $input): array {
		$tester = new CommandTester(new DescriptorListCommand($this->descriptors));
		$exit   = $tester->execute($input, ['decorated' => false]);

		return [$exit, $tester->getDisplay(true)];
	}//end runCommand()

	/**
	 * One inventory row.
	 *
	 * @param string      $slug      The register slug.
	 * @param string      $state     The state.
	 * @param string|null $installed The installed version.
	 *
	 * @return array<string, string|null>
	 */
	private static function row(string $slug, string $state, ?string $installed): array {
		return [
			'appId'            => 'openregister',
			'slug'             => $slug,
			'title'            => ucfirst($slug),
			'state'            => $state,
			'installedVersion' => $installed,
			'shippedVersion'   => '1.3.0',
			'descriptor'       => $slug . '_register.json',
		];
	}//end row()

	/**
	 * The three-state inventory the listing tests read.
	 *
	 * @return array<int, array<string, string|null>>
	 */
	private static function inventory(): array {
		return [
			self::row('flows', RegisterDescriptorService::STATE_CURRENT, '1.3.0'),
			self::row('ori', RegisterDescriptorService::STATE_ABSENT, null),
			self::row('dossiq', RegisterDescriptorService::STATE_BEHIND, '1.0.0'),
			self::row('pages', RegisterDescriptorService::STATE_CURRENT, '1.3.0'),
		];
	}//end inventory()

	/**
	 * A real re-import result.
	 *
	 * @param string $path    Which import path ran.
	 * @param string $outcome The outcome.
	 *
	 * @return array<string, mixed>
	 */
	private static function realResult(string $path, string $outcome): array {
		return [
			'outcome'   => $outcome,
			'reason'    => 'disk full',
			'version'   => '2.1.0',
			'via'       => 'SettingsService::forceImport',
			'path'      => $path,
			'fragments' => 3,
			'counts'    => [
				'registers'             => ['updated' => 1, 'created' => 2, 'unchanged' => 3],
				'schemas'               => ['updated' => 4, 'created' => 5, 'unchanged' => 6],
				'authorizationReplaced' => 2,
			],
			'replaced'  => [
				['schema' => 'case', 'path' => 'authorization.read'],
				['schema' => 'task', 'path' => 'authorization.update'],
			],
		];
	}//end realResult()

	/**
	 * The full listing.
	 *
	 * @return void
	 */
	public function testFullListingOutput(): void {
		$this->descriptors->method('inventory')->willReturn(self::inventory());

		$expected = "  openregister           flows                    current   installed v1.3.0 · shipped v1.3.0\n"
			."  openregister           ori                      absent    shipped v1.3.0\n"
			."  openregister           dossiq                   behind    installed v1.0.0 · shipped v1.3.0\n"
			."  openregister           pages                    current   installed v1.3.0 · shipped v1.3.0\n"
			."\n"
			."4 declared · 2 current · 1 behind · 1 ABSENT\n";

		$this->assertSame([0, $expected], $this->runCommand([]));
	}//end testFullListingOutput()

	/**
	 * The problems-only listing still counts every row.
	 *
	 * @return void
	 */
	public function testProblemsOnlyOutput(): void {
		$this->descriptors->method('inventory')->willReturn(self::inventory());

		$expected = "  openregister           ori                      absent    shipped v1.3.0\n"
			."  openregister           dossiq                   behind    installed v1.0.0 · shipped v1.3.0\n"
			."\n"
			."4 declared · 2 current · 1 behind · 1 ABSENT\n";

		$this->assertSame([0, $expected], $this->runCommand(['--problems-only' => true]));
	}//end testProblemsOnlyOutput()

	/**
	 * The decorated listing keeps the error and comment tags per state.
	 *
	 * @return void
	 */
	public function testStateTagsInRawOutput(): void {
		$this->descriptors->method('inventory')->willReturn(self::inventory());
		$tester = new CommandTester(new DescriptorListCommand($this->descriptors));
		$tester->execute([], ['decorated' => true]);

		$display = $tester->getDisplay(true);
		$this->assertStringContainsString("\033[37;41m  openregister           ori", $display);
		$this->assertStringContainsString("\033[33m  openregister           dossiq", $display);
		$this->assertStringContainsString("\n  openregister           flows", "\n" . $display);
	}//end testStateTagsInRawOutput()

	/**
	 * No descriptors at all.
	 *
	 * @return void
	 */
	public function testEmptyInventoryOutput(): void {
		$this->descriptors->method('inventory')->willReturn([]);

		$this->assertSame([0, "No installed app ships a register descriptor.\n"], $this->runCommand([]));
	}//end testEmptyInventoryOutput()

	/**
	 * The import without --app.
	 *
	 * @return void
	 */
	public function testImportWithoutAppOutput(): void {
		$this->descriptors->expects($this->never())->method('reimportShipped');

		$expected = "--import requires --app naming the app whose configuration to re-import.\n";
		$this->assertSame([1, $expected], $this->runCommand(['--import' => 'flows']));
	}//end testImportWithoutAppOutput()

	/**
	 * The real re-import through the app's own entry point.
	 *
	 * @return void
	 */
	public function testRealImportOutput(): void {
		$this->descriptors->expects($this->once())->method('reimportShipped')->with(new ShippedRegister('procest', 'flows'))
			->willReturn(self::realResult('settings-service', 'imported'));

		$expected = "procest: register configuration v2.1.0 re-imported through SettingsService::forceImport.\n"
			."  registers: 1 updated, 2 created, 3 unchanged\n"
			."  schemas: 4 updated, 5 created, 6 unchanged\n"
			."  authorization taken from the file over a change made on this instance: 2 block(s)\n"
			."    case: authorization.read\n"
			."    task: authorization.update\n";

		$this->assertSame([0, $expected], $this->runCommand(['--import' => 'flows', '--app' => 'procest']));
	}//end testRealImportOutput()

	/**
	 * The real re-import that OpenRegister ran itself, and failed.
	 *
	 * @return void
	 */
	public function testFallbackFailedImportOutput(): void {
		$this->descriptors->method('reimportShipped')
			->willReturn(self::realResult(AppRegisterImportRunner::PATH_OPENREGISTER, 'failed'));

		$expected = "procest: register configuration v2.1.0 re-imported through SettingsService::forceImport.\n"
			."  registers: 1 updated, 2 created, 3 unchanged\n"
			."  schemas: 4 updated, 5 created, 6 unchanged\n"
			."  authorization taken from the file over a change made on this instance: 2 block(s)\n"
			."    case: authorization.read\n"
			."    task: authorization.update\n"
			."  procest has no forced import entry point in its SettingsService, "
			."so OpenRegister imported the file and 3 register.d fragment(s) itself. "
			."The app's own post-import steps did not run.\n"
			."disk full\n";

		$this->assertSame([1, $expected], $this->runCommand(['--import' => 'flows', '--app' => 'procest']));
	}//end testFallbackFailedImportOutput()

	/**
	 * A failed real re-import without counts prints only the reason.
	 *
	 * @return void
	 */
	public function testFailedImportWithoutCountsOutput(): void {
		$this->descriptors->method('reimportShipped')->willReturn(['outcome' => 'failed', 'reason' => 'no such register']);

		$this->assertSame([1, "no such register\n"], $this->runCommand(['--import' => 'x', '--app' => 'procest']));
	}//end testFailedImportWithoutCountsOutput()

	/**
	 * A succeeded real re-import without counts prints nothing.
	 *
	 * @return void
	 */
	public function testSucceededImportWithoutCountsOutput(): void {
		$this->descriptors->method('reimportShipped')->willReturn(['outcome' => 'imported']);

		$this->assertSame([0, ''], $this->runCommand(['--import' => 'x', '--app' => 'procest']));
	}//end testSucceededImportWithoutCountsOutput()

	/**
	 * The mock import, each of its three messages.
	 *
	 * @return void
	 */
	public function testMockImportOutputs(): void {
		$this->descriptors->expects($this->exactly(4))->method('reimportMock')->with('larpinq', 'larpinq')
			->willReturnOnConsecutiveCalls(
				['outcome' => 'imported', 'counts' => ['declared' => 30, 'imported' => 28, 'skipped' => 2]],
				['outcome' => 'imported', 'counts' => ['declared' => 0, 'imported' => 0, 'skipped' => 0]],
				['outcome' => 'unchanged'],
				['outcome' => 'failed', 'reason' => 'mock missing']
			);

		$input = ['--import' => 'larpinq', '--app' => 'larpinq', '--mock' => true];
		$this->assertSame(
			[0, "larpinq: sample data for register \"larpinq\" imported: 28 of 30 demo object(s), 2 skipped.\n"],
			$this->runCommand($input)
		);
		$this->assertSame([0, "larpinq: sample descriptor for register \"larpinq\" imported.\n"], $this->runCommand($input));
		$this->assertSame([0, "larpinq: sample descriptor for register \"larpinq\" unchanged.\n"], $this->runCommand($input));
		$this->assertSame([1, "mock missing\n"], $this->runCommand($input));
	}//end testMockImportOutputs()

	/**
	 * The option definition: names, order, modes and descriptions.
	 *
	 * @return void
	 */
	public function testOptionDefinition(): void {
		$command = new DescriptorListCommand($this->descriptors);
		$this->assertSame('openregister:descriptors:list', $command->getName());
		$this->assertSame(
			'List every register descriptor an installed app ships, and whether it landed on this instance.',
			$command->getDescription()
		);

		$options = [];
		foreach ($command->getDefinition()->getOptions() as $option) {
			$options[] = [$option->getName(), $option->getShortcut(), $option->acceptValue(), $option->isValueRequired(), md5($option->getDescription())];
		}

		$this->assertSame(
			[
				['problems-only', null, false, false, md5('Show only registers that are absent or behind the descriptor their app ships')],
				['import', null, true, true, '02b873daa0275c596c2562abf76637be'],
				['mock', null, false, false, 'f0b88f5f959af62e607eb5ad26683e12'],
				['app', null, true, true, md5('The app whose configuration --import re-imports.')],
			],
			$options
		);
	}//end testOptionDefinition()
}//end class
