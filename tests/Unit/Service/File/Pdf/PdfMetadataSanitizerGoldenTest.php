<?php

/**
 * Golden test pinning PdfMetadataSanitizer's lookup and strip outcomes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File\Pdf
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File\Pdf;

use ddn\sapp\PDFDoc;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each missing or dangling reference on the Info and XMP paths, the
 * strip list versus preserved fields, and the logged diagnostic.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer
 */
final class PdfMetadataSanitizerGoldenTest extends TestCase {

	private const XMP_PII = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><rdf:Description>'
		. '<dc:title lang="nl">Geheim</dc:title><xmp:CreatorTool>Word</xmp:CreatorTool><dc:empty/>'
		. '<wcm:caseId>K-1</wcm:caseId></rdf:Description></rdf:RDF></x:xmpmeta>';

	private const XMP_CLEAN = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><wcm:caseId>K-1</wcm:caseId><dc:empty/></x:xmpmeta>';

	/**
	 * Build a PDF from explicit trailer / catalog / extra objects.
	 *
	 * @param string             $trailerExtra Extra trailer entries
	 * @param string             $rootExtra    Extra catalog entries
	 * @param array<int, string> $extra        Extra objects by id
	 *
	 * @return string
	 */
	private static function pdf(string $trailerExtra, string $rootExtra, array $extra): string {
		$objects = [
			1 => "<<\n  /Type /Catalog\n  /Pages 2 0 R\n" . $rootExtra . ">>",
			2 => "<<\n  /Type /Pages\n  /Kids [ 3 0 R ]\n  /Count 1\n>>",
			3 => "<<\n  /Type /Page\n  /Parent 2 0 R\n  /MediaBox [ 0 0 612 792 ]\n>>",
		] + $extra;
		ksort($objects);

		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf .= $oid . " 0 obj\n" . $body . "\nendobj\n";
		}

		$size = max(array_keys($objects)) + 1;
		$xrefStart = strlen($pdf);
		$pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ($oid = 1; $oid < $size; $oid++) {
			if (isset($offsets[$oid]) === true) {
				$pdf .= sprintf("%010d 00000 n \n", $offsets[$oid]);
				continue;
			}

			$pdf .= "0000000000 65535 f \n";
		}

		return $pdf . "trailer\n<<\n  /Size " . $size . "\n  /Root 1 0 R\n" . $trailerExtra . ">>\nstartxref\n" . $xrefStart . "\n%%EOF\n";
	}//end pdf()

	/**
	 * An XMP metadata stream object.
	 *
	 * @param string $xmp Stream body
	 *
	 * @return string
	 */
	private static function xmp(string $xmp): string {
		return "<<\n  /Type /Metadata\n  /Subtype /XML\n  /Length " . strlen($xmp) . "\n>>\nstream\n" . $xmp . "\nendstream";
	}//end xmp()

	/**
	 * Document shapes and the diagnostic they produce.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function shapes(): array {
		$info = "<<\n  /Title (T)\n  /Author (A)\n  /Producer (P)\n  /CreationDate (D)\n  /Keywords (K)\n>>";
		return [
			'no info, no metadata' => ['', '', [], 0, false],
			'info strips listed fields only' => ["  /Info 6 0 R\n", '', [6 => $info], 3, false],
			'info without listed fields' => ["  /Info 6 0 R\n", '', [6 => "<<\n  /Producer (P)\n>>"], 0, false],
			'info dangling' => ["  /Info 9 0 R\n", '', [], 0, false],
			'metadata dangling' => ['', "  /Metadata 9 0 R\n", [], 0, false],
			'metadata without target namespaces' => ['', "  /Metadata 7 0 R\n", [7 => self::xmp(self::XMP_CLEAN)], 0, false],
			'metadata with pii' => ["  /Info 6 0 R\n", "  /Metadata 7 0 R\n", [6 => $info, 7 => self::xmp(self::XMP_PII)], 3, true],
		];
	}//end shapes()

	/**
	 * Diagnostics, serialised output and the log line for each shape.
	 *
	 * @param string             $trailer  Extra trailer entries
	 * @param string             $root     Extra catalog entries
	 * @param array<int, string> $extra    Extra objects
	 * @param int                $stripped Expected info_fields_stripped
	 * @param bool               $xmp      Expected xmp_stripped
	 *
	 * @return void
	 */
	#[DataProvider('shapes')]
	public function testShapes(string $trailer, string $root, array $extra, int $stripped, bool $xmp): void {
		if (class_exists(PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}

		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		$doc = PDFDoc::from_string(self::pdf($trailer, $root, $extra));
		$this->assertNotFalse($doc);
		$diagnostic = (new PdfMetadataSanitizer($logger))->sanitize($doc);

		$expected = ['info_fields_stripped' => $stripped, 'xmp_stripped' => $xmp];
		$this->assertSame($expected, $diagnostic);
		$this->assertSame([['[PdfMetadataSanitizer] PDF metadata sanitised', $expected]], $logged);

		if ($stripped === 0 && $xmp === false) {
			return;
		}

		$out = (string)$doc->to_pdf_file_s(true);
		if ($stripped > 0) {
			$this->assertStringContainsString('/CreationDate(D)', $out);
			$this->assertStringNotContainsString('/Author(A)', $out);
			$this->assertSame(3, substr_count($out, '([REDACTED])'));
		}

		if ($xmp === true) {
			$this->assertStringContainsString('<dc:title lang="nl">[REDACTED]</dc:title>', $out);
			$this->assertStringContainsString('<xmp:CreatorTool>[REDACTED]</xmp:CreatorTool>', $out);
			$this->assertStringContainsString('<dc:empty/>', $out);
			$this->assertStringContainsString('<wcm:caseId>K-1</wcm:caseId>', $out);
		}
	}//end testShapes()
}//end class
