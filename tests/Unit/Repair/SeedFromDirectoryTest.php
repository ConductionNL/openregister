<?php

/**
 * Tests for SeedFromDirectory: the install and upgrade repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\SeedFromDirectory;
use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Configuration\SeedReport;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SeedFromDirectory::class)]
#[UsesClass(SeedReport::class)]
class SeedFromDirectoryTest extends TestCase {

	private SeedDirectoryImporter&MockObject $importer;

	private IOutput&MockObject $output;

	/** @var list<string> */
	private array $infos = [];

	/** @var list<string> */
	private array $warnings = [];

	protected function setUp(): void {
		$this->importer = $this->createMock(SeedDirectoryImporter::class);
		$this->output   = $this->createMock(IOutput::class);
		$this->output->method('info')->willReturnCallback(fn (string $message) => $this->infos[] = $message);
		$this->output->method('warning')->willReturnCallback(fn (string $message) => $this->warnings[] = $message);
	}//end setUp()

	private function report(array $files): SeedReport {
		return new SeedReport(directory: '/seed', digest: 'abc', moment: '2026-11-13T10:00:00+00:00', files: $files);
	}//end report()

	public function testTheStepImportsWhateverTheDigest(): void {
		$this->importer->expects(self::once())->method('import')->willReturn(
			$this->report(files: [SeedReport::fileFromResult(file: 'a.json', result: ['registers' => ['r']])])
		);
		$this->importer->expects(self::never())->method('importIfChanged');

		(new SeedFromDirectory(importer: $this->importer))->run($this->output);

		self::assertSame(['Seed success: 1 file(s), 1 imported, 0 skipped, 0 failure(s)'], $this->infos);
		self::assertSame([], $this->warnings);
		self::assertSame('Import the OpenRegister seed directory', (new SeedFromDirectory(importer: $this->importer))->getName());
	}//end testTheStepImportsWhateverTheDigest()

	public function testAPartialRunWarnsAndNamesEachFailure(): void {
		$this->importer->method('import')->willReturn(
			$this->report(
				files: [
					SeedReport::fileFromResult(file: 'a.json', result: ['registers' => ['r']]),
					SeedReport::refusedFile(file: 'b.json', reason: 'b.json resolves outside the seed directory'),
				]
			)
		);

		(new SeedFromDirectory(importer: $this->importer))->run($this->output);

		self::assertSame(
			['Seed partial: 2 file(s), 1 imported, 0 skipped, 1 failure(s)', '  b.json resolves outside the seed directory'],
			$this->warnings
		);
	}//end testAPartialRunWarnsAndNamesEachFailure()

	public function testNoDirectoryIsSaidAndNothingWarns(): void {
		$this->importer->method('import')->willReturn(null);

		(new SeedFromDirectory(importer: $this->importer))->run($this->output);

		self::assertSame(['No seed directory configured (openregister.seed_directory)'], $this->infos);
		self::assertSame([], $this->warnings);
	}//end testNoDirectoryIsSaidAndNothingWarns()

	public function testAThrowingImportNeverStopsTheUpgrade(): void {
		$this->importer->method('import')->willThrowException(new RuntimeException('database gone'));

		(new SeedFromDirectory(importer: $this->importer))->run($this->output);

		self::assertSame(['Seed directory import failed: database gone'], $this->warnings);
	}//end testAThrowingImportNeverStopsTheUpgrade()
}//end class
