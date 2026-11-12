<?php

/**
 * Characterisation tests for SourceRowReader: the rows it reads out of a CSV,
 * an Excel and a JSON file, and every reason it refuses one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\SourceRowReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceRowReaderTest extends TestCase {

	/**
	 * Files written by a test, removed afterwards.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			if (is_file($file) === true) {
				unlink($file);
			}
		}

		$this->files = [];
		parent::tearDown();
	}

	private function file(string $contents, string $extension): string {
		$path = tempnam(sys_get_temp_dir(), 'or-reader-test-').$extension;
		file_put_contents($path, $contents);
		$this->files[] = $path;

		return $path;
	}

	public function testAnUnknownFormatIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown source format "xml". Use one of: csv, excel, json.');

		(new SourceRowReader())->read('/irrelevant', 'xml');
	}

	public function testACsvSkipsBlankCellsAndBlankRowsAndTrimsHeaders(): void {
		$path = $this->file("bsn, naam ,,leeg\n111,\"Jansen, J\",x,\n,,,\n222,,,\n", '.csv');

		$rows = (new SourceRowReader())->read($path, SourceRowReader::FORMAT_CSV);

		$this->assertSame(
			[
				['row' => 2, 'data' => ['bsn' => 111, 'naam' => 'Jansen, J']],
				['row' => 4, 'data' => ['bsn' => 222]],
			],
			$rows
		);
	}

	public function testACsvWithoutHeadersIsRefused(): void {
		$path = $this->file(" ,\n1,2\n", '.csv');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No column headers were found in the source file.');

		(new SourceRowReader())->read($path, SourceRowReader::FORMAT_CSV);
	}

	public function testAnExcelFileIsReadLikeACsv(): void {
		$spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->setCellValue('A1', 'bsn');
		$sheet->setCellValue('B1', 'naam');
		$sheet->setCellValue('A2', '111');
		$sheet->setCellValue('B2', 'Jansen');
		$sheet->setCellValue('B4', 'Yilmaz');
		$path = tempnam(sys_get_temp_dir(), 'or-reader-test-').'.xlsx';
		$this->files[] = $path;
		(new XlsxWriter($spreadsheet))->save($path);

		$rows = (new SourceRowReader())->read($path, SourceRowReader::FORMAT_EXCEL);

		$this->assertSame(
			[
				['row' => 2, 'data' => ['bsn' => 111, 'naam' => 'Jansen']],
				['row' => 4, 'data' => ['naam' => 'Yilmaz']],
			],
			$rows
		);
	}

	public function testAJsonListIsReadSkippingNonObjects(): void {
		$path = $this->file('[{"bsn":"1"},"text",{"bsn":"2"}]', '.json');

		$this->assertSame(
			[
				['row' => 1, 'data' => ['bsn' => '1']],
				['row' => 3, 'data' => ['bsn' => '2']],
			],
			(new SourceRowReader())->read($path, SourceRowReader::FORMAT_JSON)
		);
	}

	public function testAJsonResultsEnvelopeIsUnwrapped(): void {
		$path = $this->file('{"total":1,"results":[{"bsn":"1"}]}', '.json');

		$this->assertSame(
			[['row' => 1, 'data' => ['bsn' => '1']]],
			(new SourceRowReader())->read($path, SourceRowReader::FORMAT_JSON)
		);
	}

	public function testAnEmptyJsonListReadsNoRows(): void {
		$path = $this->file('[]', '.json');

		$this->assertSame([], (new SourceRowReader())->read($path, SourceRowReader::FORMAT_JSON));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function badJson(): array {
		$noList = 'The source file holds no list of objects. Pass a JSON array, or an object with a "results" array.';

		return [
			'not json' => ['not json', 'The source file is not a JSON document.'],
			'a scalar' => ['"text"', 'The source file is not a JSON document.'],
			'an object without results' => ['{"a":1}', $noList],
			'results that are not a list' => ['{"results":{"a":1}}', $noList],
			'results that are a scalar' => ['{"results":"x"}', $noList],
		];
	}

	#[DataProvider('badJson')]
	public function testABadJsonDocumentIsRefused(string $contents, string $message): void {
		$path = $this->file($contents, '.json');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new SourceRowReader())->read($path, SourceRowReader::FORMAT_JSON);
	}

	public function testTheFormatIsGuessedFromTheName(): void {
		$reader = new SourceRowReader();

		$this->assertSame('csv', $reader->formatFromName('a.CSV'));
		$this->assertSame('excel', $reader->formatFromName('a.xls'));
		$this->assertSame('excel', $reader->formatFromName('a.xlsx'));
		$this->assertSame('json', $reader->formatFromName('a.json'));
		$this->assertNull($reader->formatFromName('a'));
	}
}
