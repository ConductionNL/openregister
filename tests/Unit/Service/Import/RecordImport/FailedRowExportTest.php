<?php

/**
 * FailedRowExport: failed rows in the input's columns plus a reason column, round-trippable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import\RecordImport;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Import\RecordImport\FailedRowExport;
use OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader;
use PHPUnit\Framework\TestCase;

/**
 * Files are written to a temporary directory and read back by the real reader.
 *
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\FailedRowExport
 * @uses   \OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader
 */
class FailedRowExportTest extends TestCase {

	/**
	 * The files written by a test.
	 *
	 * @var list<string>
	 */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	private function file(string $content, string $extension): string {
		$path = tempnam(sys_get_temp_dir(), 'fre') . '.' . $extension;
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}

	private function export(): FailedRowExport {
		return new FailedRowExport(new RecordFileReader());
	}

	public function testOnlyTheFailedLinesAreKeptInTheInputColumnsWithTheirReason(): void {
		$path = $this->file("reference;name;postcode\nA1;Alpha;1011AB\nA2;Beta;bad\nA3;\"Gamma, Inc\";3011CD\n", 'csv');

		$result = $this->export()->export($path, RecordFileReader::CSV, [
			['line' => 3, 'reason' => 'postcode is not a postcode'],
		]);

		$this->assertSame(1, $result['rows']);
		$this->assertSame(0, $result['unplaced']);
		$this->assertSame("reference,name,postcode,reason\nA2,Beta,bad,\"postcode is not a postcode\"\n", $result['csv']);
	}

	public function testValuesWithSeparatorsAreQuotedSoTheFileRoundTrips(): void {
		$path = $this->file("reference;name\nA1;Alpha\nA3;\"Gamma, Inc\"\n", 'csv');

		$result = $this->export()->export($path, RecordFileReader::CSV, [['line' => 3, 'reason' => 'duplicate']]);
		$back = $this->file($result['csv'], 'csv');
		$rows = iterator_to_array((new RecordFileReader())->rows($back, RecordFileReader::CSV), false);

		$this->assertSame([['line' => 2, 'values' => ['reference' => 'A3', 'name' => 'Gamma, Inc', 'reason' => 'duplicate']]], $rows);
	}

	public function testTwoFailuresOnOneLineShareTheRow(): void {
		$path = $this->file("reference\nA1\nA2\n", 'csv');

		$result = $this->export()->export($path, RecordFileReader::CSV, [
			['line' => 2, 'reason' => 'first'],
			['line' => 2, 'reason' => 'second'],
		]);

		$this->assertSame(1, $result['rows']);
		$this->assertSame("reference,reason\nA1,\"first; second\"\n", $result['csv']);
	}

	public function testAFailureWithoutALineIsCountedNotPlaced(): void {
		$path = $this->file("reference\nA1\n", 'csv');

		$result = $this->export()->export($path, RecordFileReader::CSV, [['line' => null, 'reason' => 'batch failed']]);

		$this->assertSame(0, $result['rows']);
		$this->assertSame(1, $result['unplaced']);
		$this->assertSame("reference,reason\n", $result['csv']);
	}

	public function testAnExistingReasonColumnKeepsItsNameAndValue(): void {
		$path = $this->file("reference,reason\nA1,moved\n", 'csv');

		$result = $this->export()->export($path, RecordFileReader::CSV, [['line' => 2, 'reason' => 'invalid']]);

		$this->assertSame("reference,reason,import_reason\nA1,moved,invalid\n", $result['csv']);
	}

	public function testJsonLinesExportAsCsvInFirstSeenColumnOrderWithStructuredValuesAsJson(): void {
		$path = $this->file("{\"reference\":\"A1\",\"tags\":[\"x\",\"y\"]}\n{\"reference\":\"A2\",\"active\":true}\n", 'jsonl');

		$result = $this->export()->export($path, RecordFileReader::JSONL, [
			['line' => 1, 'reason' => 'r1'],
			['line' => 2, 'reason' => 'r2'],
		]);

		$this->assertSame(2, $result['rows']);
		$this->assertSame("reference,tags,active,reason\nA1,\"[\"\"x\"\",\"\"y\"\"]\",,r1\nA2,,true,r2\n", $result['csv']);
	}
}
