<?php

declare(strict_types=1);

/**
 * SpreadsheetExtractor Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Service\TextExtraction\SpreadsheetExtractor;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SpreadsheetExtractor.
 *
 * Uses real PhpSpreadsheet CSV parsing (no ZipArchive extension required,
 * unlike xlsx/ods) to verify the happy path with genuine cell data, and
 * exercises the failure path with unparsable content.
 */
class SpreadsheetExtractorTest extends TestCase {

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private SpreadsheetExtractor $extractor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->extractor = new SpreadsheetExtractor(logger: $this->logger);
	}

	/**
	 * Build a mock Nextcloud File returning the given bytes.
	 *
	 * @param string $content Raw file content.
	 *
	 * @return File&MockObject
	 */
	private function mockFile(string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getName')->willReturn('test.csv');
		$file->method('getId')->willReturn(101);

		return $file;
	}

	/**
	 * A real CSV, parsed via real PhpSpreadsheet, should yield the known
	 * cell values in the extracted text.
	 *
	 * @return void
	 */
	public function testExtractReturnsTextForRealCsv(): void {
		$csv = "Name,Age\nAlice,30\nBob,42\n";

		$result = $this->extractor->extract(file: $this->mockFile(content: $csv));

		$this->assertIsString($result);
		$this->assertStringContainsString('Alice', $result);
		$this->assertStringContainsString('30', $result);
		$this->assertStringContainsString('Bob', $result);
		$this->assertStringContainsString('42', $result);
		$this->assertStringContainsString('Sheet: Worksheet', $result);
	}

	/**
	 * Content that PhpSpreadsheet cannot recognise as any known spreadsheet
	 * format must surface as a wrapped Exception (existing behaviour of the
	 * former TextExtractionService::extractSpreadsheet()).
	 *
	 * @return void
	 */
	public function testExtractThrowsOnUnparsableContent(): void {
		// Binary noise is not recognised as CSV/XLSX/ODS/etc. by IOFactory.
		$garbage = "\x00\x01\x02\x03garbage-not-a-spreadsheet\xFF\xFE";

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/Spreadsheet extraction failed/');

		$this->extractor->extract(file: $this->mockFile(content: $garbage));
	}

	/**
	 * Sanity check: constructing the extractor stores the logger without
	 * side effects (no calls made until extract() runs).
	 *
	 * @return void
	 */
	public function testConstructorDoesNotLog(): void {
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');
		$this->logger->expects($this->never())->method('debug');

		new SpreadsheetExtractor(logger: $this->logger);
	}
}//end class
