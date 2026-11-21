<?php

/**
 * Tests for SeedImportCommand: occ openregister:seed:import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\SeedImportCommand;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SeedImportCommand::class)]
#[UsesClass(SeedReport::class)]
class SeedImportCommandTest extends TestCase {

	private SeedDirectoryImporter&MockObject $importer;

	protected function setUp(): void {
		$this->importer = $this->createMock(SeedDirectoryImporter::class);
	}//end setUp()

	private function report(array $files): SeedReport {
		return new SeedReport(directory: '/seed', digest: 'abc', moment: '2026-11-13T10:00:00+00:00', files: $files);
	}//end report()

	private function execute(): CommandTester {
		$tester = new CommandTester(new SeedImportCommand(importer: $this->importer));
		$tester->execute([]);

		return $tester;
	}//end execute()

	public function testThePartialExitCodeIsNonZero(): void {
		$this->importer->expects(self::once())->method('import')->willReturn(
			$this->report(
				files: [
					SeedReport::fileFromResult(file: '10-seed.json', result: ['schemas' => ['s'], 'objects' => array_fill(0, 10, 'o'), 'failed' => ['schemas' => [['key' => 'Untitled', 'slug' => '', 'error' => 'a schema needs a slug']]]]),
				]
			)
		);

		$tester = $this->execute();

		self::assertSame(SeedImportCommand::EXIT_PARTIAL, $tester->getStatusCode());
		$display = $tester->getDisplay();
		self::assertStringContainsString('Seed partial: 1 file(s), 11 imported, 0 skipped, 1 failure(s)', $display);
		self::assertStringContainsString('10-seed.json: failed (registers 0/0/0, schemas 1/0/1, objects 10/0/0 imported/skipped/failed)', $display);
		self::assertStringContainsString('failed: a schema needs a slug', $display);
	}//end testThePartialExitCodeIsNonZero()

	public function testAFailedRunExitsOne(): void {
		$this->importer->method('import')->willReturn(
			$this->report(files: [SeedReport::failedFile(file: 'a.json', reason: 'a.json is not valid JSON: Syntax error')])
		);

		self::assertSame(1, $this->execute()->getStatusCode());
	}//end testAFailedRunExitsOne()

	public function testASuccessfulRunExitsZero(): void {
		$this->importer->method('import')->willReturn(
			$this->report(files: [SeedReport::fileFromResult(file: 'a.json', result: ['registers' => ['r']])])
		);

		$tester = $this->execute();

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('a.json: imported', $tester->getDisplay());
	}//end testASuccessfulRunExitsZero()

	public function testNoDirectoryExitsOneAndSaysWhatToSet(): void {
		$this->importer->method('import')->willReturn(null);

		$tester = $this->execute();

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('set openregister.seed_directory in config.php', $tester->getDisplay());
		self::assertSame('openregister:seed:import', (new SeedImportCommand(importer: $this->importer))->getName());
	}//end testNoDirectoryExitsOneAndSaysWhatToSet()
}//end class
