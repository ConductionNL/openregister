<?php

/**
 * SpreadsheetExtractorGoldenTest
 *
 * Golden tests that pin the exact text and log lines
 * {@see \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor}
 * produces today for multi-sheet workbooks, CSV, a workbook without values
 * and unreadable bytes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor;
use OCP\Files\File;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins SpreadsheetExtractor::extract().
 *
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\SpreadsheetExtractor
 */
class SpreadsheetExtractorGoldenTest extends TestCase {

	/**
	 * Log lines as "level message {context without line}".
	 *
	 * @var list<string>
	 */
	public array $logLines = [];

	/**
	 * Extract from bytes through a mocked file.
	 *
	 * @param string $bytes The file bytes.
	 *
	 * @return string|null
	 */
	private function extract(string $bytes): ?string {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(77);
		$file->method('getName')->willReturn('lijst.xlsx');
		$file->method('getContent')->willReturn($bytes);
		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			public function __construct(private readonly SpreadsheetExtractorGoldenTest $test) {
			}

			public function log($level, \Stringable|string $message, array $context = []): void {
				unset($context['line']);
				$context['file'] = basename((string) $context['file']);
				$this->test->logLines[] = $level.' '.$message.' '.json_encode($context);
			}
		};

		return (new SpreadsheetExtractor($logger))->extract($file);
	}//end extract()

	/**
	 * Xlsx bytes for a workbook built by a callback.
	 *
	 * @param callable(Spreadsheet):void $build Fills the workbook.
	 *
	 * @return string
	 */
	private static function xlsx(callable $build): string {
		$book = new Spreadsheet();
		$build($book);
		$path = (string) tempnam(sys_get_temp_dir(), 'or-xlsx');
		(new Xlsx($book))->save($path);
		$bytes = (string) file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end xlsx()

	/**
	 * A two-sheet workbook: gaps, empty rows, zero, formulas and wide columns.
	 *
	 * @return void
	 */
	public function testWorkbook(): void {
		$bytes = self::xlsx(
			static function (Spreadsheet $book): void {
				$first = $book->getActiveSheet();
				$first->setTitle('Personen');
				$first->fromArray([['Naam', 'Leeftijd', null, 'Stad'], ['Jan', 0, '', 'Demostad'], [null, null, null, null], ['Piet', '=B2+1', 'x', '']], null, 'A1', true);
				$second = $book->createSheet();
				$second->setTitle('Breed');
				$second->setCellValue('B2', 'midden');
				$second->setCellValue('AB3', 'ver');
				$book->createSheet()->setTitle('Leeg');
			}
		);

		self::assertSame(
			"Sheet: Personen\nNaam\tLeeftijd\tStad\nJan\t0\tDemostad\nPiet\t=B2+1\tx\n\n"
			."Sheet: Breed\nmidden\nver\n\n"
			."Sheet: Leeg\n\n",
			$this->extract($bytes)
		);
		self::assertSame(
			[
				'debug [SpreadsheetExtractor] Extracting spreadsheet {"file":"SpreadsheetExtractor.php","fileId":77,"name":"lijst.xlsx"}',
				'debug [SpreadsheetExtractor] Spreadsheet extracted successfully {"file":"SpreadsheetExtractor.php","fileId":77,"length":102}',
			],
			$this->logLines
		);
	}//end testWorkbook()

	/**
	 * CSV bytes read as one sheet.
	 *
	 * @return void
	 */
	public function testCsv(): void {
		self::assertSame("Sheet: Worksheet\na\tb\tc\n1\t3\n\n", $this->extract("a,b,c\n1,,3\n"));
	}//end testCsv()

	/**
	 * A workbook without values has no text: its sheet headers alone do not count.
	 *
	 * @return void
	 */
	public function testEmptyWorkbook(): void {
		self::assertNull($this->extract(self::xlsx(static function (Spreadsheet $book): void {
			$book->getActiveSheet()->setTitle('Empty');
			$book->createSheet()->setTitle('Leeg');
		})));
		self::assertSame(
			[
				'debug [SpreadsheetExtractor] Extracting spreadsheet {"file":"SpreadsheetExtractor.php","fileId":77,"name":"lijst.xlsx"}',
				'warning [SpreadsheetExtractor] Spreadsheet extraction returned empty text {"file":"SpreadsheetExtractor.php","fileId":77}',
			],
			$this->logLines
		);
	}//end testEmptyWorkbook()

	/**
	 * Unreadable bytes are logged as an error and rethrown wrapped.
	 *
	 * @return void
	 */
	public function testUnreadable(): void {
		try {
			$this->extract("\x00\x01\x02\xFF\xFE binary noise \x00");
			self::fail('expected exception');
		} catch (Exception $e) {
			$inner = substr($e->getMessage(), strlen('Spreadsheet extraction failed: '));
			self::assertStringStartsWith('Spreadsheet extraction failed: ', $e->getMessage());
			self::assertNotSame('', $inner);
		}

		self::assertCount(2, $this->logLines);
		self::assertSame(
			'error [SpreadsheetExtractor] Spreadsheet extraction failed '.json_encode(['file' => 'SpreadsheetExtractor.php', 'fileId' => 77, 'error' => $inner]),
			$this->logLines[1]
		);
	}//end testUnreadable()
}//end class
