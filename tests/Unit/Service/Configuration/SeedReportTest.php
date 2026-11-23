<?php

/**
 * Tests for SeedReport: the counts, the named failures and the run status.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\SeedReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeedReport::class)]
class SeedReportTest extends TestCase {

	private function importResult(array $overrides = []): array {
		return array_replace_recursive(
			[
				'registers' => [],
				'schemas'   => [],
				'objects'   => [],
				'skipped'   => ['registers' => 0, 'schemas' => 0, 'objects' => 0, 'mappings' => 0, 'seedObjects' => 0],
				'unchanged' => ['objects' => 0],
				'failed'    => ['schemas' => [], 'objects' => []],
			],
			$overrides
		);
	}//end importResult()

	private function report(array $files, array $problems = []): SeedReport {
		return new SeedReport(directory: '/seed', digest: 'abc', moment: '2026-11-13T10:00:00+00:00', files: $files, problems: $problems);
	}//end report()

	public function testASchemaWithoutASlugMakesTheRunPartial(): void {
		$result = $this->importResult(
			[
				'schemas' => ['valid'],
				'objects' => array_fill(0, 10, 'object'),
				'skipped' => ['schemas' => 1],
				'failed'  => ['schemas' => [['key' => 'Untitled', 'slug' => '', 'error' => 'a schema needs a slug']]],
			]
		);

		$report = $this->report(files: [SeedReport::fileFromResult(file: '10-seed.json', result: $result)]);

		self::assertSame(SeedReport::STATUS_PARTIAL, $report->status());
		self::assertSame(['imported' => 10, 'skipped' => 0, 'failed' => 0], $report->files[0]['counts']['objects']);
		self::assertSame(['imported' => 1, 'skipped' => 0, 'failed' => 1], $report->files[0]['counts']['schemas']);
		self::assertSame(SeedReport::FILE_FAILED, $report->files[0]['status']);
		self::assertSame(
			[['file' => '10-seed.json', 'entity' => 'schema', 'slug' => 'Untitled', 'reason' => 'a schema needs a slug']],
			$report->failures()
		);
	}//end testASchemaWithoutASlugMakesTheRunPartial()

	public function testFailuresAreNamed(): void {
		$result = $this->importResult(
			[
				'registers' => ['r'],
				'skipped'   => ['registers' => 2, 'objects' => 3],
				'failed'    => ['objects' => [['slug' => 'theme-1', 'error' => 'title is required'], ['slug' => 'theme-2', 'error' => 'duplicate']]],
				'unchanged' => ['objects' => 4],
			]
		);

		$report = $this->report(
			files: [
				SeedReport::fileFromResult(file: '20-objects.json', result: $result),
				SeedReport::refusedFile(file: '99-secrets.json', reason: '99-secrets.json resolves outside the seed directory'),
				SeedReport::failedFile(file: '50-bad.json', reason: '50-bad.json is not valid JSON: Syntax error'),
			]
		);

		self::assertSame(
			[
				['file' => '20-objects.json', 'entity' => 'object', 'slug' => 'theme-1', 'reason' => 'title is required'],
				['file' => '20-objects.json', 'entity' => 'object', 'slug' => 'theme-2', 'reason' => 'duplicate'],
				['file' => '20-objects.json', 'entity' => 'register', 'slug' => '', 'reason' => '2 register(s) could not be imported; the log names them'],
				['file' => '99-secrets.json', 'entity' => 'file', 'slug' => '', 'reason' => '99-secrets.json resolves outside the seed directory'],
				['file' => '50-bad.json', 'entity' => 'file', 'slug' => '', 'reason' => '50-bad.json is not valid JSON: Syntax error'],
			],
			$report->failures()
		);
		// Unchanged objects plus the one skipped that was not a failure (3 skipped - 2 failed).
		self::assertSame(['imported' => 0, 'skipped' => 5, 'failed' => 2], $report->files[0]['counts']['objects']);
		$array = $report->toArray();
		self::assertSame(['imported' => 1, 'skipped' => 5, 'failed' => 6], $array['totals']);
		self::assertSame('partial', $array['status']);
		self::assertSame('Seed partial: 3 file(s), 1 imported, 5 skipped, 5 failure(s)', $report->summary());
	}//end testFailuresAreNamed()

	public function testAFailureWithNothingImportedIsFailed(): void {
		$report = $this->report(files: [SeedReport::failedFile(file: 'a.json', reason: 'a.json is not valid JSON: Syntax error')]);

		self::assertSame(SeedReport::STATUS_FAILED, $report->status());
	}//end testAFailureWithNothingImportedIsFailed()

	public function testAnUnchangedRunIsASuccess(): void {
		$file   = SeedReport::fileFromResult(file: 'a.json', result: $this->importResult(['unchanged' => ['objects' => 3]]));
		$report = $this->report(files: [$file]);

		self::assertSame(SeedReport::FILE_UNCHANGED, $file['status']);
		self::assertSame(SeedReport::STATUS_SUCCESS, $report->status());
		self::assertSame(3, $report->total(column: 'skipped'));
	}//end testAnUnchangedRunIsASuccess()

	public function testAnImportedFileIsASuccess(): void {
		$file = SeedReport::fileFromResult(file: 'a.json', result: $this->importResult(['registers' => ['r'], 'schemas' => ['s', 't']]));

		self::assertSame(SeedReport::FILE_IMPORTED, $file['status']);
		self::assertSame(SeedReport::STATUS_SUCCESS, $this->report(files: [$file])->status());
		self::assertSame([], $file['failures']);
	}//end testAnImportedFileIsASuccess()

	public function testADirectoryProblemFailsTheRun(): void {
		$report = $this->report(files: [], problems: ['the seed directory "seed" is not an absolute path']);

		self::assertSame(SeedReport::STATUS_FAILED, $report->status());
		self::assertSame('Seed failed: 0 file(s), 0 imported, 0 skipped, 0 failure(s): the seed directory "seed" is not an absolute path', $report->summary());
		self::assertSame(['the seed directory "seed" is not an absolute path'], $report->toArray()['problems']);
	}//end testADirectoryProblemFailsTheRun()

	public function testUnreadTopLevelObjectsAreNotCountedAsImported(): void {
		// Only what the import RETURNED counts: objects the file listed but the importer did not write are absent.
		$file = SeedReport::fileFromResult(file: 'a.json', result: $this->importResult(['objects' => ['one']]));

		self::assertSame(1, $file['counts']['objects']['imported']);
	}//end testUnreadTopLevelObjectsAreNotCountedAsImported()
}//end class
