<?php

/**
 * PdfDocumentSanitizer over real PDFs built here: hidden payload is absent
 * from the output, the output is one full save, PDF/A-3 archival
 * attachments declared Source or Data are kept with their /AF association
 * and reported, every other embedded file goes, form field widgets keep
 * their appearance but lose their actions, encrypted PDFs are refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\File;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\PdfDocumentSanitizer;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\PdfDocumentSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfPayloadStripper
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfReachableSerialiser
 * @covers \OCA\OpenRegister\Files\Service\File\PdfSanitizationReport
 * @covers \OCA\OpenRegister\Files\Service\File\PdfSanitizationResult
 * @uses   \OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer
 * @uses   \OCA\OpenRegister\Exception\SanitizationException
 */
class PdfDocumentSanitizerTest extends TestCase {

	/**
	 * Temporary files to remove.
	 *
	 * @var list<string>
	 */
	private array $temp = [];

	protected function setUp(): void {
		parent::setUp();
		if (class_exists(PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->temp as $path) {
			@unlink($path);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * A PDF from object bodies, with a classic xref and trailer.
	 *
	 * @param array<int, string> $objects Object id => body (dictionary, optionally with a stream).
	 * @param string             $trailer Extra trailer entries.
	 *
	 * @return string The PDF bytes.
	 */
	private static function pdf(array $objects, string $trailer = '/Info 9 0 R'): string {
		ksort($objects);
		$out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($out);
			$out .= $oid . " 0 obj\n" . $body . "\nendobj\n";
		}

		$size = max(array_keys($objects)) + 1;
		$xref = strlen($out);
		$out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ($oid = 1; $oid < $size; $oid++) {
			$out .= isset($offsets[$oid]) ? sprintf("%010d 00000 n \n", $offsets[$oid]) : "0000000000 65535 f \n";
		}

		return $out . "trailer\n<< /Size " . $size . ' /Root 1 0 R ' . $trailer . " >>\nstartxref\n" . $xref . "\n%%EOF\n";

	}//end pdf()

	/**
	 * A stream object body.
	 *
	 * @param string $dict    Extra dictionary entries.
	 * @param string $content The stream content.
	 *
	 * @return string The body.
	 */
	private static function stream(string $dict, string $content): string {
		return '<< ' . $dict . ' /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

	}//end stream()

	/**
	 * The page tree and one page with body text; the page carries $pageExtra.
	 *
	 * @param string $pageExtra Extra page entries.
	 *
	 * @return array<int, string> Objects 2 to 5.
	 */
	private static function onePage(string $pageExtra = ''): array {
		return [
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R ' . $pageExtra . ' >>',
			4 => self::stream('', "BT /F1 12 Tf 72 720 Td (Body text stays.) Tj ET"),
			5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
			9 => '<< /Author (Jan Jansen) /Producer (Writer) >>',
		];

	}//end onePage()

	/**
	 * The sanitiser.
	 *
	 * @param IRootFolder|null $root The file tree.
	 *
	 * @return PdfDocumentSanitizer The sanitiser.
	 */
	private function sanitizer(?IRootFolder $root = null): PdfDocumentSanitizer {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (string $postFix = ''): string {
				$path = (string) tempnam(sys_get_temp_dir(), 'or-pdf-sanitize-') . $postFix;
				$this->temp[] = $path;

				return $path;
			}
		);

		return new PdfDocumentSanitizer(
			$root ?? $this->createMock(IRootFolder::class),
			$temp,
			new PdfMetadataSanitizer(new NullLogger()),
			new NullLogger()
		);

	}//end sanitizer()

	public function testHiddenPayloadIsAbsentFromTheSanitizedPdf(): void {
		$bytes = self::pdf(
			[
				1 => '<< /Type /Catalog /Pages 2 0 R /OpenAction 20 0 R /AA << /WC 20 0 R >> /Names << /JavaScript 21 0 R /EmbeddedFiles 22 0 R >> >>',
				10 => '<< /Type /Annot /Subtype /Text /Rect [ 10 10 20 20 ] /Contents (Reviewer note: weigh Jan Jansen) >>',
				20 => '<< /S /JavaScript /JS (app.alert\(1\)) >>',
				21 => '<< /Names [ (init) 20 0 R ] >>',
				22 => '<< /Names [ (memo.txt) 23 0 R ] >>',
				23 => '<< /Type /Filespec /F (memo.txt) /UF (memo.txt) /EF << /F 24 0 R >> >>',
				24 => self::stream('/Type /EmbeddedFile', 'SECRET-ATTACHMENT-BYTES'),
			] + self::onePage(pageExtra: '/Annots [ 10 0 R ] /AA << /O 20 0 R >>')
		);

		[$clean, $report] = $this->sanitizer()->sanitizeBytes(bytes: $bytes);

		foreach (['Jan Jansen', 'Reviewer note', '/Annots', 'SECRET-ATTACHMENT-BYTES', '/OpenAction', '/JavaScript', 'app.alert', '/AA', '/EmbeddedFiles', '/Filespec'] as $gone) {
			$this->assertStringNotContainsString($gone, $clean, $gone . ' must be removed, not masked.');
		}

		$this->assertStringContainsString('(Body text stays.) Tj', $clean, 'Page content is not touched.');
		$this->assertSame(1, substr_count($clean, '%%EOF'), 'One full save.');
		$this->assertSame(1, substr_count($clean, "\nxref\n"));
		$this->assertNotFalse(PDFDoc::from_string($clean), 'The output parses.');
		$this->assertSame(
			[
				'metadataFieldsScrubbed' => 1,
				'xmpScrubbed' => false,
				'annotationsRemoved' => 1,
				'embeddedFilesRemoved' => 1,
				'javascriptRemoved' => 1,
				'openActionsRemoved' => 1,
				'additionalActionsRemoved' => 2,
				'preservedAttachments' => [],
			],
			$report->jsonSerialize()
		);

	}//end testHiddenPayloadIsAbsentFromTheSanitizedPdf()

	public function testAnIncrementalUpdateLeavesNoRemnant(): void {
		$base = self::pdf([1 => '<< /Type /Catalog /Pages 2 0 R >>'] + self::onePage());
		$startxref = (int) substr($base, strrpos($base, 'startxref') + 10);
		$update = "9 0 obj\n<< /Author (Piet Pietersen) /Producer (Writer) >>\nendobj\n";
		$offset = strlen($base);
		$xref = $offset + strlen($update);
		$bytes = $base . $update . "xref\n9 1\n" . sprintf("%010d 00000 n \n", $offset)
			. "trailer\n<< /Size 10 /Root 1 0 R /Info 9 0 R /Prev " . $startxref . " >>\nstartxref\n" . $xref . "\n%%EOF\n";

		[$clean] = $this->sanitizer()->sanitizeBytes(bytes: $bytes);

		$this->assertStringNotContainsString('Jan Jansen', $clean, 'The earlier revision is gone.');
		$this->assertStringNotContainsString('Piet Pietersen', $clean);
		$this->assertStringNotContainsString('/Prev', $clean);
		$this->assertSame(1, substr_count($clean, '%%EOF'));

	}//end testAnIncrementalUpdateLeavesNoRemnant()

	/**
	 * A PDF/A-3 document with a Source sidecar, an Unspecified attachment, both in /AF.
	 *
	 * @param string $part The pdfaid:part the XMP declares.
	 *
	 * @return string The bytes.
	 */
	private static function pdfa3(string $part = '3'): string {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/" pdfaid:part="' . $part . '" pdfaid:conformance="B"/>'
			. '</rdf:RDF></x:xmpmeta>';

		return self::pdf(
			[
				1 => '<< /Type /Catalog /Pages 2 0 R /Metadata 6 0 R /AF [ 30 0 R 32 0 R ] /Names << /EmbeddedFiles 29 0 R >> >>',
				6 => self::stream('/Type /Metadata /Subtype /XML', $xmp),
				29 => '<< /Names [ (mdto.xml) 30 0 R (begroting.xlsx) 32 0 R ] >>',
				30 => '<< /Type /Filespec /F (mdto.xml) /UF (mdto.xml) /AFRelationship /Source /EF << /F 31 0 R >> >>',
				31 => self::stream('/Type /EmbeddedFile /Subtype /text#2Fxml', 'MDTO-SIDECAR-BYTES'),
				32 => '<< /Type /Filespec /F (begroting.xlsx) /UF (begroting.xlsx) /AFRelationship /Unspecified /EF << /F 33 0 R >> >>',
				33 => self::stream('/Type /EmbeddedFile', 'SPREADSHEET-BYTES'),
			] + self::onePage()
		);

	}//end pdfa3()

	public function testADeclaredPdfA3ArchivalAttachmentIsPreserved(): void {
		[$clean, $report] = $this->sanitizer()->sanitizeBytes(bytes: self::pdfa3());

		$this->assertStringContainsString('MDTO-SIDECAR-BYTES', $clean);
		$this->assertMatchesRegularExpression('#/AF\s*\[\s*30 0 R\s*\]#', $clean, 'The /AF association binds the sidecar only.');
		$this->assertMatchesRegularExpression('#/AFRelationship\s*/Source#', $clean);
		$this->assertStringNotContainsString('SPREADSHEET-BYTES', $clean);
		$this->assertStringNotContainsString('begroting.xlsx', $clean);
		$this->assertSame([['name' => 'mdto.xml', 'relationship' => 'Source']], $report->preservedAttachments);
		$this->assertSame(1, $report->embeddedFilesRemoved);
		$this->assertNotFalse(PDFDoc::from_string($clean));

	}//end testADeclaredPdfA3ArchivalAttachmentIsPreserved()

	public function testOutsidePdfA3ASourceAttachmentIsStripped(): void {
		[$clean, $report] = $this->sanitizer()->sanitizeBytes(bytes: self::pdfa3(part: '2'));

		$this->assertStringNotContainsString('MDTO-SIDECAR-BYTES', $clean);
		$this->assertStringNotContainsString('/AF ', $clean);
		$this->assertSame([], $report->preservedAttachments);
		$this->assertSame(2, $report->embeddedFilesRemoved);

	}//end testOutsidePdfA3ASourceAttachmentIsStripped()

	public function testAFormFieldKeepsItsWidgetButLosesItsActions(): void {
		$bytes = self::pdf(
			[
				1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [ 11 0 R ] >> >>',
				10 => '<< /Type /Annot /Subtype /Link /Rect [ 0 0 5 5 ] /A << /S /URI /URI (https://example.org) >> >>',
				11 => '<< /Type /Annot /Subtype /Widget /FT /Tx /T (naam) /Rect [ 50 50 200 70 ] /AA << /K 12 0 R >> /A 12 0 R >>',
				12 => '<< /S /JavaScript /JS (this.submitForm\(\)) >>',
			] + self::onePage(pageExtra: '/Annots [ 10 0 R 11 0 R ]')
		);

		[$clean, $report] = $this->sanitizer()->sanitizeBytes(bytes: $bytes);

		$this->assertMatchesRegularExpression('#/Annots\s*\[\s*11 0 R\s*\]#', $clean);
		$this->assertStringContainsString('/Widget', $clean);
		$this->assertStringNotContainsString('submitForm', $clean);
		$this->assertStringNotContainsString('https://example.org', $clean);
		$this->assertSame(1, $report->annotationsRemoved);
		$this->assertSame(1, $report->javascriptRemoved);
		$this->assertSame(1, $report->additionalActionsRemoved);

	}//end testAFormFieldKeepsItsWidgetButLosesItsActions()

	public function testAnEncryptedPdfIsRefused(): void {
		$bytes = self::pdf([1 => '<< /Type /Catalog /Pages 2 0 R >>', 8 => '<< /Filter /Standard /V 2 /R 3 >>'] + self::onePage(), '/Info 9 0 R /Encrypt 8 0 R');

		try {
			$this->sanitizer()->sanitizeBytes(bytes: $bytes);
			$this->fail('An encrypted PDF must be refused.');
		} catch (SanitizationException $e) {
			$this->assertSame(SanitizationException::REASON_ENCRYPTED, $e->getReason());
		}

	}//end testAnEncryptedPdfIsRefused()

	public function testBytesThatAreNotAPdfAreRefused(): void {
		$this->expectException(SanitizationException::class);

		$this->sanitizer()->sanitizeBytes(bytes: 'PK zip bytes');

	}//end testBytesThatAreNotAPdfAreRefused()

	public function testAStoredPdfIsSanitizedIntoATemporaryFileAndTheSourceIsUntouched(): void {
		$bytes = self::pdf([1 => '<< /Type /Catalog /Pages 2 0 R /OpenAction 20 0 R >>', 20 => '<< /S /JavaScript /JS (x\(\)) >>'] + self::onePage());
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getContent')->willReturn($bytes);
		$file->expects($this->never())->method('putContent');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->with(812)->willReturn([$file]);

		$result = $this->sanitizer(root: $root)->sanitize(fileId: 812);

		$this->assertFileExists($result->path);
		$this->assertStringNotContainsString('/OpenAction', (string) file_get_contents($result->path));
		$this->assertSame(1, $result->report->openActionsRemoved);
		$this->assertTrue($this->sanitizer()->isSanitizable(mimeType: 'application/pdf'));
		$this->assertFalse($this->sanitizer()->isSanitizable(mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

	}//end testAStoredPdfIsSanitizedIntoATemporaryFileAndTheSourceIsUntouched()

	public function testAFileThatIsMissingOrNotAPdfIsUnsupported(): void {
		$docx = $this->createMock(File::class);
		$docx->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnCallback(fn (int $id): array => ($id === 1 ? [$docx] : ($id === 2 ? [$this->createMock(Folder::class)] : [])));

		foreach ([1, 2, 3] as $id) {
			try {
				$this->sanitizer(root: $root)->sanitize(fileId: $id);
				$this->fail('File ' . $id . ' must be refused.');
			} catch (SanitizationException $e) {
				$this->assertSame(SanitizationException::REASON_UNSUPPORTED_MIME, $e->getReason());
			}
		}

	}//end testAFileThatIsMissingOrNotAPdfIsUnsupported()
}//end class
