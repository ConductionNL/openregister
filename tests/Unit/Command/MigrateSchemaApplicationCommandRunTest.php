<?php

/**
 * Characterisation of the migrate-application command's real run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\MigrateSchemaApplicationCommand;
use OCA\OpenRegister\Service\SchemaApplicationMigratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pins the real run's outcomes: a refusal on colliding slugs, and the move report.
 */
class MigrateSchemaApplicationCommandRunTest extends TestCase {
	/**
	 * Run the command for real against a migrator that answers $result.
	 *
	 * @param array<string, mixed> $result What migrate() answers.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function migrateFor(array $result): array {
		$migrator = $this->createMock(SchemaApplicationMigratorInterface::class);
		$migrator->method('countFor')->willReturn(1);
		$migrator->expects($this->never())->method('collidingSlugs');
		$migrator->expects($this->once())->method('migrate')->with('procest', 'dossiq')->willReturn($result);

		$tester = new CommandTester(new MigrateSchemaApplicationCommand($migrator));
		$code   = $tester->execute(['from' => 'procest', 'to' => 'dossiq']);

		return [$code, $tester->getDisplay()];
	}//end migrateFor()

	/**
	 * A refused migration names every colliding slug and exits 1.
	 *
	 * @return void
	 */
	public function testARefusedRunListsTheCollisions(): void {
		$result = $this->migrateFor(['ok' => false, 'collisions' => ['casetype', 'zaak']]);

		$this->assertSame(1, $result[0]);
		$this->assertSame(
			'Application "procest" currently owns 1 schema(s) and 1 register(s).'."\n"
			.'Refusing: these slugs already exist under the target application id.'."\n"
			.'  - casetype'."\n"
			.'  - zaak'."\n"
			.'An import has already forked these schemas. Resolve the duplicates first (see openregister:schemas:dedup).'."\n",
			$result[1]
		);
	}//end testARefusedRunListsTheCollisions()

	/**
	 * A completed migration reports what moved and exits 0.
	 *
	 * @return void
	 */
	public function testACompletedRunReportsWhatMoved(): void {
		$result = $this->migrateFor(['ok' => true, 'schemas' => 3, 'registers' => 2]);

		$this->assertSame(0, $result[0]);
		$this->assertStringContainsString('Moved 3 schema(s) and 2 register(s) from "procest" to "dossiq".', $result[1]);
		$this->assertStringNotContainsString('Refusing', $result[1]);
	}//end testACompletedRunReportsWhatMoved()

	/**
	 * A missing or unchanged application id is refused before anything is counted.
	 *
	 * @return void
	 */
	public function testTheIdsMustBothBeGivenAndDiffer(): void {
		$migrator = $this->createMock(SchemaApplicationMigratorInterface::class);
		$migrator->expects($this->never())->method('countFor');
		$migrator->expects($this->never())->method('migrate');
		$tester = new CommandTester(new MigrateSchemaApplicationCommand($migrator));

		foreach ([['from' => '', 'to' => 'dossiq'], ['from' => 'procest', 'to' => ''], ['from' => 'procest', 'to' => 'procest']] as $arguments) {
			$this->assertSame(1, $tester->execute($arguments));
			$this->assertSame('`from` and `to` must both be given and must differ.'."\n", $tester->getDisplay());
		}
	}//end testTheIdsMustBothBeGivenAndDiffer()

	/**
	 * Nothing owned by the old id says how much the new id owns, exits 0 and migrates nothing, dry run or not.
	 *
	 * @return void
	 */
	public function testNothingOwnedSaysWhatTheNewIdOwns(): void {
		$migrator = $this->createMock(SchemaApplicationMigratorInterface::class);
		$migrator->method('countFor')->willReturnMap(
			[
				['openregister_schemas', 'procest', 0],
				['openregister_registers', 'procest', 0],
				['openregister_schemas', 'dossiq', 4],
			]
		);
		$migrator->expects($this->never())->method('migrate');
		$migrator->expects($this->never())->method('collidingSlugs');
		$tester = new CommandTester(new MigrateSchemaApplicationCommand($migrator));

		foreach ([[], ['--dry-run' => true]] as $options) {
			$this->assertSame(0, $tester->execute(['from' => 'procest', 'to' => 'dossiq'] + $options));
			$this->assertSame(
				'Application "procest" currently owns 0 schema(s) and 0 register(s).'."\n"
				.'Nothing owned by "procest". "dossiq" owns 4 schema(s).'."\n",
				$tester->getDisplay()
			);
		}
	}//end testNothingOwnedSaysWhatTheNewIdOwns()

	/**
	 * A completed run prints exactly the ownership line, the move line and the objects note.
	 *
	 * @return void
	 */
	public function testACompletedRunPrintsExactlyThreeLines(): void {
		$result = $this->migrateFor(['ok' => true, 'schemas' => 3, 'registers' => 2]);

		$this->assertSame(
			'Application "procest" currently owns 1 schema(s) and 1 register(s).'."\n"
			.'Moved 3 schema(s) and 2 register(s) from "procest" to "dossiq".'."\n"
			.'Objects are unaffected: they reference their schema by id, which does not change here.'."\n",
			$result[1]
		);
	}//end testACompletedRunPrintsExactlyThreeLines()
}//end class
