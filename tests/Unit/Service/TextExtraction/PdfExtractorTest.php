<?php

declare(strict_types=1);

/**
 * PdfExtractor Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Service\TextExtraction\PdfExtractor;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PdfExtractor.
 *
 * The happy path is exercised against a real PDF fixture already vendored
 * with smalot/pdfparser's dependency (ddn/sapp examples) so extraction runs
 * through the genuine Smalot\PdfParser\Parser rather than a hand-rolled
 * stub. No new binary fixtures are committed by this test.
 */
class PdfExtractorTest extends TestCase {

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private PdfExtractor $extractor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->extractor = new PdfExtractor(logger: $this->logger);
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
		$file->method('getName')->willReturn('test.pdf');
		$file->method('getId')->willReturn(202);

		return $file;
	}

	/**
	 * A real PDF must yield its known body text.
	 *
	 * The fixture is committed here rather than read from
	 * `vendor/ddn/sapp/examples/testdoc.pdf`. That path only ever resolved
	 * because composer happened to install ddn/sapp from source: the package
	 * marks `/examples export-ignore` in its .gitattributes, so any
	 * distribution archive omits the directory entirely. Switching the package
	 * to its GitHub dist (to get Codeberg off the CI critical path) made the
	 * omission visible as a single failing assertion.
	 *
	 * A test must not depend on a dependency's examples directory — the
	 * dependency has explicitly declared it not part of what it ships.
	 *
	 * @return void
	 */
	public function testExtractReturnsTextForRealPdf(): void {
		$fixturePath = dirname(__DIR__, 3) . '/fixtures/pdf/testdoc.pdf';
		$this->assertFileExists($fixturePath, 'Expected the committed PDF fixture to exist for this test');

		$bytes = (string)file_get_contents($fixturePath);

		$result = $this->extractor->extract(file: $this->mockFile(content: $bytes));

		$this->assertIsString($result);
		$this->assertStringContainsString('SAPP', $result);
	}

	/**
	 * Content without a valid PDF header must surface as a wrapped
	 * Exception (existing behaviour of the former
	 * TextExtractionService::extractPdf()).
	 *
	 * @return void
	 */
	public function testExtractThrowsOnUnparsableContent(): void {
		$garbage = 'this is definitely not a pdf file';

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/PDF extraction failed/');

		$this->extractor->extract(file: $this->mockFile(content: $garbage));
	}

	/**
	 * Empty content is likewise not a valid PDF and must raise the wrapped
	 * extraction-failed exception rather than silently returning null.
	 *
	 * @return void
	 */
	public function testExtractThrowsOnEmptyContent(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/PDF extraction failed/');

		$this->extractor->extract(file: $this->mockFile(content: ''));
	}
}//end class
