<?php

/**
 * Characterisation of the migrate-application command's dry run.
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
 * Pins the dry run's two outcomes: a would-refuse on colliding slugs, and the would-move report.
 */
class MigrateSchemaApplicationCommandDryRunTest extends TestCase {
	/**
	 * Run the dry run against a migrator that owns 3 schemas and 2 registers under `procest`.
	 *
	 * @param string[] $collisions The colliding slugs the migrator reports.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function dryRun(array $collisions): array {
		$migrator = $this->createMock(SchemaApplicationMigratorInterface::class);
		$migrator->method('countFor')->willReturnCallback(
			static function (string $table, string $application): int {
				if ($application !== 'procest') {
					return 0;
				}

				if ($table === 'openregister_schemas') {
					return 3;
				}

				return 2;
			}
		);
		$migrator->method('collidingSlugs')->willReturn($collisions);
		$migrator->expects($this->never())->method('migrate');

		$tester = new CommandTester(new MigrateSchemaApplicationCommand($migrator));
		$code   = $tester->execute(['from' => 'procest', 'to' => 'dossiq', '--dry-run' => true]);

		return [$code, $tester->getDisplay()];
	}//end dryRun()

	/**
	 * Without collisions the dry run reports the counts it would move and exits 0.
	 *
	 * @return void
	 */
	public function testDryRunReportsWhatWouldMove(): void {
		$result = $this->dryRun([]);

		$this->assertSame(0, $result[0]);
		$this->assertStringContainsString('Dry run: would move 3 schema(s) and 2 register(s) from "procest" to "dossiq".', $result[1]);
		$this->assertStringNotContainsString('Would refuse', $result[1]);
	}//end testDryRunReportsWhatWouldMove()

	/**
	 * With collisions the dry run says it would refuse, lists the slugs and exits 1.
	 *
	 * @return void
	 */
	public function testDryRunWouldRefuseOnCollisions(): void {
		$result = $this->dryRun(['casetype']);

		$this->assertSame(1, $result[0]);
		$this->assertStringContainsString('Would refuse:', $result[1]);
		$this->assertStringContainsString('  - casetype', $result[1]);
		$this->assertStringNotContainsString('would move', $result[1]);
	}//end testDryRunWouldRefuseOnCollisions()
}//end class
