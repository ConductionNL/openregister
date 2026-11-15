<?php

/**
 * RecordFileReader: CSV, TSV, XLSX and JSON-lines rows, read lazily with their line numbers.
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

use Generator;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * Files are written to a temporary directory; nothing is mocked.
 *
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\RecordFileReader
 * @covers \OCA\OpenRegister\Service\Import\RecordImport\RowRangeFilter
 */
class RecordFileReaderTest extends TestCase {

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
		$path = tempnam(sys_get_temp_dir(), 'rec') . '.' . $extension;
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}

	/**
	 * Read every row.
	 *
	 * @param string $path   The file.
	 * @param string $format The format.
	 *
	 * @return list<array{line: int, values: array<string, mixed>}>
	 */
	private function read(string $path, string $format): array {
		return iterator_to_array((new RecordFileReader())->rows($path, $format), false);
	}

	public function testACsvIsReadByHeaderWithItsLineNumbers(): void {
		$path = $this->file("\xEF\xBB\xBFreference,name,city\nA-1,\"Jansen, P.\",Utrecht\n\n,,\nA-2,De Vries,\n", 'csv');

		$this->assertSame(
			[
				['line' => 2, 'values' => ['reference' => 'A-1', 'name' => 'Jansen, P.', 'city' => 'Utrecht']],
				['line' => 5, 'values' => ['reference' => 'A-2', 'name' => 'De Vries', 'city' => '']],
			],
			$this->read($path, RecordFileReader::CSV)
		);
		$this->assertSame(['reference', 'name', 'city'], (new RecordFileReader())->columns($path, RecordFileReader::CSV));
	}

	public function testASemicolonCsvIsRecognised(): void {
		$path = $this->file("postcode;huisnummer\n1234AB;12\n", 'csv');

		$this->assertSame([['line' => 2, 'values' => ['postcode' => '1234AB', 'huisnummer' => '12']]], $this->read($path, RecordFileReader::CSV));
	}

	public function testAShortRowGetsNullAndAnUnnamedColumnIsNamedByPosition(): void {
		$path = $this->file("a\t\tc\n1\n", 'tsv');

		$this->assertSame([['line' => 2, 'values' => ['a' => '1', 'column 2' => null, 'c' => null]]], $this->read($path, RecordFileReader::TSV));
	}

	public function testJsonLinesAreObjectsAndTheirKeysAreTheColumns(): void {
		$path = $this->file("{\"reference\":\"A-1\",\"amount\":3}\n\n{\"reference\":\"A-2\",\"note\":\"x\"}\n", 'jsonl');

		$this->assertSame(
			[
				['line' => 1, 'values' => ['reference' => 'A-1', 'amount' => 3]],
				['line' => 3, 'values' => ['reference' => 'A-2', 'note' => 'x']],
			],
			$this->read($path, RecordFileReader::JSONL)
		);
		$this->assertSame(['reference', 'amount', 'note'], (new RecordFileReader())->columns($path, RecordFileReader::JSONL));
	}

	public function testAJsonLineThatIsNoObjectIsRefusedWithItsLine(): void {
		$path = $this->file("{\"a\":1}\n[1,2]\n", 'jsonl');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Line 2 is not a JSON object.');

		$this->read($path, RecordFileReader::JSONL);
	}

	public function testAnXlsxIsReadInChunksAcrossTheBoundary(): void {
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->fromArray(['reference', 'amount'], null, 'A1');
		for ($row = 2; $row <= 1203; $row++) {
			$sheet->fromArray(['R-' . $row, $row * 2], null, 'A' . $row);
		}

		$path = $this->file('', 'xlsx');
		(new Xlsx($spreadsheet))->save($path);

		$rows = $this->read($path, RecordFileReader::XLSX);

		$this->assertCount(1202, $rows);
		$this->assertSame(['line' => 2, 'values' => ['reference' => 'R-2', 'amount' => 4]], $rows[0]);
		$this->assertSame(['line' => 501, 'values' => ['reference' => 'R-501', 'amount' => 1002]], $rows[499]);
		$this->assertSame(['line' => 1203, 'values' => ['reference' => 'R-1203', 'amount' => 2406]], $rows[1201]);
		$this->assertSame(['reference', 'amount'], (new RecordFileReader())->columns($path, RecordFileReader::XLSX));
	}

	public function testRowsAreAGeneratorReadOnDemand(): void {
		$path = $this->file("a\n1\n2\n", 'csv');

		$rows = (new RecordFileReader())->rows($path, RecordFileReader::CSV);

		$this->assertInstanceOf(Generator::class, $rows);
		$this->assertSame(['line' => 2, 'values' => ['a' => '1']], $rows->current());
	}

	public function testTheFormatComesFromTheExtension(): void {
		$this->assertSame(RecordFileReader::CSV, RecordFileReader::formatOf('adressen.CSV'));
		$this->assertSame(RecordFileReader::TSV, RecordFileReader::formatOf('a.tab'));
		$this->assertSame(RecordFileReader::XLSX, RecordFileReader::formatOf('a.xlsx'));
		$this->assertSame(RecordFileReader::JSONL, RecordFileReader::formatOf('a.ndjson'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('"besluit.pdf" is not a record file: use CSV, TSV, XLSX or JSON lines.');
		RecordFileReader::formatOf('besluit.pdf');
	}

	public function testAnUnknownFormatAndAnUnreadableFileAreRefused(): void {
		try {
			$this->read($this->file("a\n", 'txt'), 'txt');
			$this->fail('an unknown format should be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('Unknown record file format "txt".', $e->getMessage());
		}

		$this->expectExceptionMessage('The record file cannot be read.');
		$this->read('/nonexistent/x.csv', RecordFileReader::CSV);
	}
}
