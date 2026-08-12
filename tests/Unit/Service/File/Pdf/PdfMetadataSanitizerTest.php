<?php

/**
 * PdfMetadataSanitizerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\File\Pdf\PdfMetadataSanitizer}
 * covering /Info field stripping, XMP-namespace stripping, and
 * preservation of custom (non-stripped) namespaces.
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

namespace Unit\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Service\File\Pdf\PdfMetadataSanitizer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see PdfMetadataSanitizer}.
 *
 * Fixtures are synthesised in-test — a minimal one-page PDF with a
 * populated /Info dict and an XMP /Metadata stream covering the three
 * stripped namespaces (dc, xmp, pdf) plus one preserved namespace
 * (a custom prefix), per the change spec.
 */
class PdfMetadataSanitizerTest extends TestCase {

	/**
	 * Mock logger used to capture diagnostic surface emissions.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * System-under-test instance, recreated per test in setUp.
	 *
	 * @var PdfMetadataSanitizer
	 */
	private PdfMetadataSanitizer $sanitizer;

	/**
	 * Reset the system-under-test before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// The PDF sanitiser is backed by the optional `ddn/sapp` library
		// (fetched from a Codeberg VCS repo). Some deploy/CI containers
		// ship without it; skip rather than error so the gateable subset
		// stays honest where the dependency is absent.
		if (class_exists(PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->sanitizer = new PdfMetadataSanitizer(logger: $this->logger);
	}//end setUp()

	/**
	 * Build a one-page PDF with /Info + /Metadata stream populated.
	 *
	 * @param array<string, string> $infoFields Per-field values to
	 *                                          embed in the /Info dict.
	 * @param string|null $xmpBody Raw XMP stream body to
	 *                             embed; null skips XMP.
	 *
	 * @return string The synthesised PDF bytes.
	 */
	private static function buildFixtureWithMetadata(
		array $infoFields,
		?string $xmpBody,
	): string {
		$contentStream = "BT\n/F1 12 Tf\n72 720 Td\n(Body text.) Tj\nET\n";
		$compressed = gzcompress($contentStream, 6);

		$obj = static function (int $oid, string $body): string {
			return $oid . " 0 obj\n" . $body . "\nendobj\n";
		};

		$infoDictLines = [];
		foreach ($infoFields as $key => $value) {
			$infoDictLines[] = '  /' . $key . ' (' . $value . ')';
		}

		$infoDict = "<<\n" . implode("\n", $infoDictLines) . "\n>>";

		$rootDict = "<<\n  /Type /Catalog\n  /Pages 2 0 R\n";
		if ($xmpBody !== null) {
			$rootDict .= "  /Metadata 7 0 R\n";
		}

		$rootDict .= '>>';

		$obj1 = $obj(1, $rootDict);
		$obj2 = $obj(2, "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>");
		$obj3 = $obj(
			3,
			"<<\n  /Type /Page\n  /Parent 2 0 R\n"
			. "  /MediaBox [ 0 0 612 792 ]\n"
			. "  /Resources <<\n    /Font << /F1 5 0 R >>\n  >>\n"
			. "  /Contents 4 0 R\n>>"
		);
		$obj4 = $obj(
			4,
			"<<\n  /Length " . strlen($compressed) . "\n  /Filter /FlateDecode\n>>\n"
			. "stream\n" . $compressed . "\nendstream"
		);
		$obj5 = $obj(
			5,
			"<<\n  /Type /Font\n  /Subtype /Type1\n  /BaseFont /Helvetica\n  /Encoding /WinAnsiEncoding\n>>"
		);
		$obj6 = $obj(6, $infoDict);

		$objs = [1 => $obj1, 2 => $obj2, 3 => $obj3, 4 => $obj4, 5 => $obj5, 6 => $obj6];

		if ($xmpBody !== null) {
			$obj7 = $obj(
				7,
				"<<\n  /Type /Metadata\n  /Subtype /XML\n  /Length " . strlen($xmpBody) . "\n>>\n"
				. "stream\n" . $xmpBody . "\nendstream"
			);
			$objs[7] = $obj7;
		}

		$size = count($objs) + 1;
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objs as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $body;
		}

		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		foreach (array_keys($objs) as $oid) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
		}

		$pdf .= "trailer\n<<\n  /Size " . $size . "\n  /Root 1 0 R\n  /Info 6 0 R\n>>\nstartxref\n" . $xrefStart . "\n%%EOF\n";

		return $pdf;
	}//end buildFixtureWithMetadata()

	/**
	 * Read back an /Info field value from a serialised PDF.
	 *
	 * @param string $pdfBytes The serialised PDF bytes.
	 * @param string $field The /Info dictionary field name.
	 *
	 * @return string|null The field value, or null when absent.
	 */
	private static function readInfoField(string $pdfBytes, string $field): ?string {
		// The /Info object body contains entries like `/Author (Conduction B.V.)`.
		if (preg_match('#/' . preg_quote(str: $field, delimiter: '#') . '\s*\(([^)]*)\)#', $pdfBytes, $m) === 1) {
			return $m[1];
		}

		return null;
	}//end readInfoField()

	/**
	 * Strip-list fields (Title, Author, Subject, Keywords, Creator) MUST
	 * be replaced with the [REDACTED] sentinel.
	 *
	 * @return void
	 */
	public function testSanitizeStripsPiiInfoFields(): void {
		$pdf = self::buildFixtureWithMetadata(
			infoFields: [
				'Title' => 'Vergunningaanvraag 2026',
				'Author' => 'Jan Jansen',
				'Subject' => 'Aanvraag voor het loket',
				'Keywords' => 'vergunning, persoon, 2026',
				'Creator' => 'Microsoft Word',
				'Producer' => 'OpenRegister',
				'CreationDate' => 'D:20260528120000',
				'ModDate' => 'D:20260528120000',
			],
			xmpBody: null
		);

		$doc = PDFDoc::from_string(buffer: $pdf);
		$this->assertNotFalse(condition: $doc, message: 'SAPP failed to load synthesised fixture');

		$diagnostic = $this->sanitizer->sanitize(doc: $doc);

		$this->assertSame(expected: 5, actual: $diagnostic['info_fields_stripped']);

		// Re-serialise and inspect the bytes. `to_pdf_file_s()` returns
		// the raw byte string; `to_pdf_file_b()` returns a Buffer whose
		// __toString() emits a debug-dump (NOT the raw bytes), so the
		// string sibling is the safe choice.
		$output = $doc->to_pdf_file_s(rebuild: true);
		$this->assertNotFalse(condition: $output, message: 'SAPP returned false on serialise');
		$this->assertNotSame(expected: '', actual: $output, message: 'SAPP returned empty output');

		foreach (['Title', 'Author', 'Subject', 'Keywords', 'Creator'] as $stripField) {
			$value = self::readInfoField(pdfBytes: $output, field: $stripField);
			$this->assertSame(
				expected: '[REDACTED]',
				actual: $value,
				message: "Field /$stripField not stripped"
			);
		}

		foreach (['Producer', 'CreationDate', 'ModDate'] as $preservedField) {
			$value = self::readInfoField(pdfBytes: $output, field: $preservedField);
			$this->assertNotSame(
				expected: '[REDACTED]',
				actual: $value,
				message: "Provenance field /$preservedField was incorrectly stripped"
			);
			$this->assertNotNull(
				actual: $value,
				message: "Provenance field /$preservedField went missing"
			);
		}
	}//end testSanitizeStripsPiiInfoFields()

	/**
	 * PDFs without /Info entries MUST be handled gracefully (no throw,
	 * `info_fields_stripped == 0`).
	 *
	 * @return void
	 */
	public function testSanitizeHandlesMissingInfoGracefully(): void {
		// Empty strip-list → the /Info dict exists but has nothing to
		// strip; assert 0.
		$pdf = self::buildFixtureWithMetadata(infoFields: [], xmpBody: null);

		$doc = PDFDoc::from_string(buffer: $pdf);
		$diagnostic = $this->sanitizer->sanitize(doc: $doc);

		$this->assertSame(expected: 0, actual: $diagnostic['info_fields_stripped']);
		$this->assertFalse(condition: $diagnostic['xmp_stripped']);
	}//end testSanitizeHandlesMissingInfoGracefully()

	/**
	 * Stripped namespaces (dc, xmp, pdf) MUST have their element bodies
	 * replaced with the sentinel; custom namespaces MUST be preserved.
	 *
	 * @return void
	 */
	public function testSanitizeXmpStripsKnownNamespacesAndPreservesCustom(): void {
		$xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about=""'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
			. ' xmlns:xmp="http://ns.adobe.com/xap/1.0/"'
			. ' xmlns:pdf="http://ns.adobe.com/pdf/1.3/"'
			. ' xmlns:wcm="https://conduction.nl/ns/workflow/1.0/">'
			. '<dc:title>Vergunningaanvraag 2026</dc:title>'
			. '<dc:creator>Jan Jansen</dc:creator>'
			. '<xmp:CreatorTool>Microsoft Word</xmp:CreatorTool>'
			. '<xmp:CreateDate>2026-05-28T12:00:00Z</xmp:CreateDate>'
			. '<pdf:Producer>OpenRegister</pdf:Producer>'
			. '<wcm:caseId>WCM-2026-12345</wcm:caseId>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>'
			. '<?xpacket end="w"?>';

		$pdf = self::buildFixtureWithMetadata(infoFields: [], xmpBody: $xmp);

		$doc = PDFDoc::from_string(buffer: $pdf);
		$diagnostic = $this->sanitizer->sanitize(doc: $doc);

		$this->assertTrue(condition: $diagnostic['xmp_stripped'], message: 'XMP was not mutated');

		$output = $doc->to_pdf_file_s(rebuild: true);
		$this->assertNotFalse(condition: $output, message: 'SAPP returned false on serialise');

		// Stripped namespaces' elements MUST contain the sentinel body
		// (dc:, xmp:, and pdf: per INFO_FIELDS_TO_STRIP / XMP_NAMESPACES_TO_STRIP).
		$this->assertMatchesRegularExpression(
			pattern: '#<dc:title[^>]*>\[REDACTED\]</dc:title>#',
			string: $output,
			message: 'dc:title body not stripped'
		);
		$this->assertMatchesRegularExpression(
			pattern: '#<xmp:CreatorTool[^>]*>\[REDACTED\]</xmp:CreatorTool>#',
			string: $output,
			message: 'xmp:CreatorTool body not stripped'
		);
		$this->assertMatchesRegularExpression(
			pattern: '#<pdf:Producer[^>]*>\[REDACTED\]</pdf:Producer>#',
			string: $output,
			message: 'pdf:Producer body not stripped'
		);

		// Custom namespace MUST be preserved.
		$this->assertStringContainsString(
			needle: '<wcm:caseId>WCM-2026-12345</wcm:caseId>',
			haystack: $output,
			message: 'Custom namespace element was incorrectly stripped'
		);
	}//end testSanitizeXmpStripsKnownNamespacesAndPreservesCustom()
}//end class
