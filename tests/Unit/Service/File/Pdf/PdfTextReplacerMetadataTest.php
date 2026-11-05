<?php

declare(strict_types=1);

/**
 * PdfTextReplacer metadata sanitising tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Pdf
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File\Pdf;

use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see PdfTextReplacer::sanitizeMetadata()}.
 */
class PdfTextReplacerMetadataTest extends TestCase {

	/**
	 * Build a one-page PDF whose /Info dictionary names `$author`.
	 *
	 * @param string $author Value of /Info /Author.
	 *
	 * @return string The PDF bytes.
	 */
	private static function buildPdf(string $author): string {
		$stream = (string)gzcompress("BT\n/F1 12 Tf\n72 720 Td\n(Body text) Tj\nET\n", 6);
		$objects = [
			1 => "<< /Type /Catalog /Pages 2 0 R >>",
			2 => "<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>",
			3 => "<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ]"
				. " /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>",
			4 => "<< /Length " . strlen($stream) . " /Filter /FlateDecode >>\nstream\n" . $stream . "\nendstream",
			5 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>",
			6 => "<< /Author (" . $author . ") >>",
		];

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $oid . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 7\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		return $pdf . "trailer\n<< /Size 7 /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF\n";
	}//end buildPdf()

	/**
	 * Build the replacer under test.
	 *
	 * @return PdfTextReplacer
	 */
	private function makeReplacer(): PdfTextReplacer {
		return new PdfTextReplacer(logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end makeReplacer()

	/**
	 * The /Info author is gone from the re-serialised PDF.
	 *
	 * @return void
	 */
	public function testSanitizeMetadataStripsInfoDictionary(): void {
		$input = self::buildPdf(author: 'Piet Author');
		$this->assertStringContainsString('Piet Author', $input);

		$output = $this->makeReplacer()->sanitizeMetadata(pdfBytes: $input);

		$this->assertStringStartsWith('%PDF-', $output);
		$this->assertStringNotContainsString('Piet Author', $output);
	}//end testSanitizeMetadataStripsInfoDictionary()

	/**
	 * Bytes the PDF library cannot reparse fail closed.
	 *
	 * @return void
	 */
	public function testSanitizeMetadataFailsClosedOnUnparsableBytes(): void {
		try {
			$this->makeReplacer()->sanitizeMetadata(pdfBytes: 'this is definitely not a pdf file');
			$this->fail('Expected a PdfAnonymisationException');
		} catch (PdfAnonymisationException $e) {
			$this->assertSame(PdfAnonymisationException::REASON_INTERNAL_ERROR, $e->getReason());
			$this->assertSame(['stage' => 'sanitize.reparse'], $e->getDiagnostic());
		}
	}//end testSanitizeMetadataFailsClosedOnUnparsableBytes()
}//end class
