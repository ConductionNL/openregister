<?php

/**
 * Text-extraction fixture test.
 *
 * Pins that a real PDF, DOCX and EML fixture each come out as readable text
 * through the extractors the text-extraction pipeline uses, so a refactor of
 * any one format reader is caught by a round trip over a genuine document
 * rather than only by its unit golden. Capability row for text extraction per
 * format (capability-rows-closing-round, task 1.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\EmlParser;
use OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor;
use OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\PdfExtractor
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\WordExtractor
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\EmlParser
 */
class TextExtractionFixturesTest extends TestCase {

	private const PDF = __DIR__ . '/../../../fixtures/pdf/testdoc.pdf';

	private const DOCX = __DIR__ . '/../../../Data/Word/word-structures.docx';

	private const EML = __DIR__ . '/../../../Fixtures/eml/simple-text.eml';

	private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * A File double that answers the given fixture's bytes, name and mime.
	 *
	 * @param string $path     The fixture path.
	 * @param string $mimeType The mime type the extractor reads.
	 *
	 * @return File The double.
	 */
	private function fileFor(string $path, string $mimeType): File {
		$bytes = (string) file_get_contents($path);
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($bytes);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getMimeType')->willReturn($mimeType);
		$file->method('getId')->willReturn(1);
		$file->method('fopen')->willReturnCallback(
			static function () use ($bytes) {
				$stream = fopen('php://temp', 'r+');
				fwrite($stream, $bytes);
				rewind($stream);
				return $stream;
			}
		);

		return $file;
	}//end fileFor()

	public function testPdfFixtureExtractsToText(): void {
		$extractor = new PdfExtractor(new NullLogger());

		$text = $extractor->extractText((string) file_get_contents(self::PDF));

		$this->assertStringContainsString('PDF document for testing purposes', $text);
	}//end testPdfFixtureExtractsToText()

	public function testDocxFixtureExtractsToText(): void {
		$extractor = new WordExtractor(new NullLogger());

		$text = $extractor->extract($this->fileFor(self::DOCX, self::DOCX_MIME));

		$this->assertNotNull($text);
		$this->assertStringContainsString('Gemeente Demostad', (string) $text);
	}//end testDocxFixtureExtractsToText()

	public function testEmlFixtureExtractsToText(): void {
		$parser = new EmlParser(new NullLogger());

		// The structure body is what parseEmlStructured() returns for indexing;
		// flatten() over a parsed Date header is covered by EmlParserGoldenTest.
		$structure = $parser->parse($this->fileFor(self::EML, 'message/rfc822'));

		$this->assertStringContainsString('This is a test email body.', (string) $structure->body->plainText);
		$this->assertStringContainsString('Test email', (string) $structure->headers['subject']);
	}//end testEmlFixtureExtractsToText()
}//end class
