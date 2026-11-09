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
}//end class
