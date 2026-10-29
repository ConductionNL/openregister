<?php

declare(strict_types=1);

/**
 * DocumentProcessingHandler PDF path tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Service\File\OfficeDocumentSanitizer;
use OCA\OpenRegister\Service\TextExtraction\PdfExtractor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the PDF branch of {@see DocumentProcessingHandler::replaceWords()}.
 */
class DocumentProcessingHandlerPdfTest extends TestCase {

	/**
	 * Build a handler with mocked collaborators.
	 *
	 * @return DocumentProcessingHandler
	 */
	private function makeHandler(): DocumentProcessingHandler {
		return new DocumentProcessingHandler(
			rootFolder: $this->createMock(originalClassName: IRootFolder::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			entityRelationMapper: $this->createMock(originalClassName: EntityRelationMapper::class),
			sanitizer: $this->createMock(originalClassName: OfficeDocumentSanitizer::class),
			anonymisationLogMapper: $this->createMock(originalClassName: AnonymisationLogMapper::class),
			l10n: null
		);
	}//end makeHandler()

	/**
	 * Build a PDF file node with the given content.
	 *
	 * @param string $content Raw file content.
	 *
	 * @return File&MockObject
	 */
	private function makePdfNode(string $content): File {
		$node = $this->createMock(originalClassName: File::class);
		$node->method('getName')->willReturn('input.pdf');
		$node->method('getContent')->willReturn($content);

		return $node;
	}//end makePdfNode()

	/**
	 * Content the parser cannot read fails at the text-layer probe.
	 *
	 * @return void
	 */
	public function testUnparsablePdfFailsAtTextLayerProbe(): void {
		try {
			$this->makeHandler()->replaceWords(
				node: $this->makePdfNode(content: 'this is definitely not a pdf file'),
				replacements: ['SAPP' => '[ORG: 1]']
			);
			$this->fail('Expected a PdfAnonymisationException');
		} catch (PdfAnonymisationException $e) {
			$this->assertSame(PdfAnonymisationException::REASON_INTERNAL_ERROR, $e->getReason());
			$this->assertSame(['stage' => 'input.text_layer_probe'], $e->getDiagnostic());
		}
	}//end testUnparsablePdfFailsAtTextLayerProbe()

	/**
	 * Build a one-page PDF whose page shows `$bodyText` and whose /Info
	 * dictionary names `$author`.
	 *
	 * @param string $bodyText Text shown on the page.
	 * @param string $author   Value of /Info /Author.
	 *
	 * @return string The PDF bytes.
	 */
	private static function buildPdf(string $bodyText, string $author): string {
		$stream = (string)gzcompress("BT\n/F1 12 Tf\n72 720 Td\n(" . $bodyText . ") Tj\nET\n", 6);
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
	 * A PDF with a text layer is rewritten, its metadata stripped, and the
	 * result written next to the input.
	 *
	 * @return void
	 */
	public function testPdfIsAnonymisedAndMetadataSanitised(): void {
		$written = null;
		$folder = $this->createMock(originalClassName: Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->expects($this->once())
			->method('newFile')
			->willReturnCallback(
				function (string $path, $content) use (&$written): File {
					$this->assertSame('input_replaced.pdf', $path);
					$written = $content;
					return $this->createMock(originalClassName: File::class);
				}
			);

		$node = $this->makePdfNode(content: self::buildPdf(bodyText: 'Dossier of Jan Jansen', author: 'Piet Author'));
		$node->method('getParent')->willReturn($folder);

		$this->makeHandler()->replaceWords(node: $node, replacements: ['Jan Jansen' => '[PERSON: 1]']);

		$this->assertIsString($written);
		$this->assertStringStartsWith('%PDF-', $written);
		$this->assertStringNotContainsString('Piet Author', $written);

		$text = (new PdfExtractor(logger: $this->createMock(originalClassName: LoggerInterface::class)))
			->extractText(pdfBytes: $written);
		$this->assertStringContainsString('[PERSON: 1]', $text);
		$this->assertStringNotContainsString('Jan Jansen', $text);
	}//end testPdfIsAnonymisedAndMetadataSanitised()
}//end class
