<?php

/**
 * RedactionIrreversibilityVerifierTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Service\Anonymisation\RedactionIrreversibilityVerifier}.
 * One leaking fixture per route, built in-test as a PDF (Flate-compressed
 * where a real writer compresses) or as an OOXML/ODF package, plus the
 * unverifiable and the clean cases.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-written-copy-cannot-be-read-back-req-rrs-001-carried-from-filinq-req-rwb-01
 */

declare(strict_types=1);

namespace Unit\Service\Anonymisation;

use OCA\OpenRegister\Service\Anonymisation\RedactionIrreversibilityVerifier;
use OCA\OpenRegister\Service\Anonymisation\RedactionLeakRoute;
use OCA\OpenRegister\Service\Anonymisation\RedactionOutputMode;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * A written copy is clean only when every route was examined and nothing was found.
 *
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionIrreversibilityVerifier
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionPdfRouteReader
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionPackageRouteReader
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionRegionCutter
 * @covers \OCA\OpenRegister\Service\Anonymisation\RedactionOutputMode
 */
class RedactionIrreversibilityVerifierTest extends TestCase {

	private const NAME = 'Jan Jansen';

	private const MARK = "0 0 0 rg 72 700 120 14 re f\n";

	private RedactionIrreversibilityVerifier $verifier;

	protected function setUp(): void {
		$this->verifier = new RedactionIrreversibilityVerifier();
	}//end setUp()

	public function testTextUnderTheMarkIsLeaking(): void {
		$pdf = $this->pdf(objects: [4 => $this->stream(content: "BT /F1 12 Tf 72 700 Td (Jan Jansen) Tj ET\n" . self::MARK)]);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(RedactionIrreversibilityVerifier::LEAKING, $result['verdict']);
		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::TEXT_UNDER_MARK));
		self::assertFalse($result['mayBePublished']);
		self::assertFalse($this->verifier->mayBePublished(result: $result));
	}//end testTextUnderTheMarkIsLeaking()

	public function testAnEmbeddedPreviewIsLeaking(): void {
		$pdf = $this->pdf(
			objects: [4 => $this->stream(content: self::MARK), 5 => $this->stream(content: 'preview of Jan Jansen')],
			pageExtra: '/Thumb 5 0 R'
		);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::EMBEDDED_PREVIEW));
		self::assertSame(0, $this->countOn(result: $result, route: RedactionLeakRoute::EMBEDDED_ATTACHMENT));
	}//end testAnEmbeddedPreviewIsLeaking()

	public function testXmpIsLeaking(): void {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF><dc:creator>Jan Jansen</dc:creator></rdf:RDF></x:xmpmeta>';
		$pdf = $this->pdf(objects: [4 => $this->stream(content: self::MARK), 5 => $this->stream(content: $xmp, dict: '/Type /Metadata /Subtype /XML')]);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::XMP));
		self::assertSame(0, $this->countOn(result: $result, route: RedactionLeakRoute::EXIF));
	}//end testXmpIsLeaking()

	public function testExifIsLeaking(): void {
		$pdf = $this->pdf(objects: [4 => $this->stream(content: self::MARK), 6 => '<< /Author (Jan Jansen) /Title (Besluit) >>'], trailerExtra: '/Info 6 0 R');

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::EXIF));
		self::assertSame(0, $this->countOn(result: $result, route: RedactionLeakRoute::XMP));
	}//end testExifIsLeaking()

	public function testAnIncrementalUpdateKeepingTheValueIsLeaking(): void {
		$first  = $this->pdf(objects: [4 => $this->stream(content: "BT (Jan Jansen) Tj ET\n")]);
		$update = "4 0 obj\n" . $this->stream(content: "BT ([PERSOON: 1]) Tj ET\n" . self::MARK) . "\nendobj\ntrailer\n<< /Root 1 0 R /Prev 9 >>\n%%EOF\n";

		$result = $this->verifier->verify(bytes: $first . $update, redactedValues: [self::NAME, 'Kerkstraat 1'], outputMode: RedactionOutputMode::PDF);

		self::assertSame(RedactionIrreversibilityVerifier::LEAKING, $result['verdict']);
		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::INCREMENTAL_UPDATE));
	}//end testAnIncrementalUpdateKeepingTheValueIsLeaking()

	public function testAnAnnotationIsLeaking(): void {
		$pdf = $this->pdf(
			objects: [4 => $this->stream(content: self::MARK), 7 => '<< /Type /Annot /Subtype /Text /Rect [0 0 10 10] /Contents (Jan Jansen) >>'],
			pageExtra: '/Annots [7 0 R]'
		);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::ANNOTATIONS_AND_FIELDS));
	}//end testAnAnnotationIsLeaking()

	public function testAnEmbeddedAttachmentIsLeaking(): void {
		$pdf = $this->pdf(objects: [4 => $this->stream(content: self::MARK), 8 => $this->stream(content: "naam;adres\nJan Jansen;Kerkstraat 1\n", dict: '/Type /EmbeddedFile')]);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME, 'Kerkstraat 1'], outputMode: RedactionOutputMode::PDF);

		self::assertSame(2, $this->countOn(result: $result, route: RedactionLeakRoute::EMBEDDED_ATTACHMENT));
		self::assertSame(0, $this->countOn(result: $result, route: RedactionLeakRoute::ANNOTATIONS_AND_FIELDS));
	}//end testAnEmbeddedAttachmentIsLeaking()

	public function testUnparsableBytesAreUnverifiable(): void {
		$notPdf = $this->verifier->verify(bytes: 'this is not a pdf', redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);
		$notZip = $this->verifier->verify(bytes: "PK\x03\x04 broken", redactedValues: [self::NAME], outputMode: RedactionOutputMode::DOCX);

		foreach ([$notPdf, $notZip] as $result) {
			self::assertSame(RedactionIrreversibilityVerifier::UNVERIFIABLE, $result['verdict']);
			self::assertFalse($result['mayBePublished']);
			self::assertSame([], $result['routesChecked']);
		}

		self::assertSame('the written copy could not be read as pdf', $notPdf['reason']);
		self::assertSame('the written copy could not be read as docx', $notZip['reason']);
	}//end testUnparsableBytesAreUnverifiable()

	public function testNoValuesIsUnverifiable(): void {
		$pdf = $this->pdf(objects: [4 => $this->stream(content: self::MARK)]);

		foreach ([[], ['Al', ' ', 'x']] as $values) {
			$result = $this->verifier->verify(bytes: $pdf, redactedValues: $values, outputMode: RedactionOutputMode::PDF);
			self::assertSame(RedactionIrreversibilityVerifier::UNVERIFIABLE, $result['verdict']);
			self::assertSame('no redacted values were supplied, so there was nothing to look for', $result['reason']);
			self::assertFalse($this->verifier->mayBePublished(result: $result));
		}
	}//end testNoValuesIsUnverifiable()

	public function testAnEmptyFileIsUnverifiable(): void {
		$result = $this->verifier->verify(bytes: " \n", redactedValues: [self::NAME], outputMode: RedactionOutputMode::TEXT);

		self::assertSame(RedactionIrreversibilityVerifier::UNVERIFIABLE, $result['verdict']);
		self::assertSame('the written copy is empty, so nothing could be examined', $result['reason']);
	}//end testAnEmptyFileIsUnverifiable()

	public function testAnUnknownOutputModeIsUnverifiable(): void {
		$result = $this->verifier->verify(bytes: 'Jan Jansen', redactedValues: [self::NAME], outputMode: 'tiff');

		self::assertSame(RedactionIrreversibilityVerifier::UNVERIFIABLE, $result['verdict']);
		self::assertSame('the written copy could not be read as tiff', $result['reason']);
	}//end testAnUnknownOutputModeIsUnverifiable()

	public function testACleanFixtureIsClean(): void {
		$xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><dc:creator>[PERSOON: 1]</dc:creator></x:xmpmeta>';
		$pdf = $this->pdf(
			objects: [
				4 => $this->stream(content: "BT ([PERSOON: 1]) Tj ET\n" . self::MARK),
				5 => $this->stream(content: $xmp, dict: '/Type /Metadata /Subtype /XML'),
				6 => '<< /Title (Besluit op bezwaar [PERSOON: 1]) >>',
			],
			trailerExtra: '/Info 6 0 R'
		);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(RedactionIrreversibilityVerifier::CLEAN, $result['verdict']);
		self::assertSame(RedactionLeakRoute::ALL, $result['routesChecked']);
		self::assertSame([], $result['findings']);
		self::assertSame(RedactionOutputMode::PDF, $result['outputMode']);
		self::assertTrue($this->verifier->mayBePublished(result: $result));
	}//end testACleanFixtureIsClean()

	public function testAFindingCarriesTheRouteAndACountNeverTheValue(): void {
		$pdf = $this->pdf(objects: [4 => $this->stream(content: "BT (Jan Jansen) Tj ET\n")]);

		$result = $this->verifier->verify(bytes: $pdf, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PDF);

		self::assertSame(
			['route' => RedactionLeakRoute::TEXT_UNDER_MARK, 'description' => RedactionLeakRoute::DESCRIPTIONS[RedactionLeakRoute::TEXT_UNDER_MARK], 'count' => 1],
			$result['findings'][0]
		);
		self::assertStringNotContainsStringIgnoringCase('jansen', (string) json_encode($result));
	}//end testAFindingCarriesTheRouteAndACountNeverTheValue()

	public function testAMarkedVerdictIsNotPublishableWithoutTheFlag(): void {
		self::assertFalse($this->verifier->mayBePublished(result: ['verdict' => RedactionIrreversibilityVerifier::CLEAN]));
		self::assertFalse($this->verifier->mayBePublished(result: ['verdict' => RedactionIrreversibilityVerifier::LEAKING, 'mayBePublished' => true]));
	}//end testAMarkedVerdictIsNotPublishableWithoutTheFlag()

	public function testADocxValueSplitOverRunsIsUnderTheMark(): void {
		$docx = $this->package(parts: ['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Jan</w:t></w:r><w:r><w:t> Jansen</w:t></w:r></w:p></w:body></w:document>']);

		$result = $this->verifier->verify(bytes: $docx, redactedValues: [self::NAME], outputMode: RedactionOutputMode::DOCX);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::TEXT_UNDER_MARK));
	}//end testADocxValueSplitOverRunsIsUnderTheMark()

	public function testADocxTrackedDeletionIsAnEarlierRevision(): void {
		$docx = $this->package(parts: ['word/document.xml' => '<w:document><w:p><w:del w:id="1" w:author="x"><w:r><w:delText>Jan Jansen</w:delText></w:r></w:del><w:r><w:t>[PERSOON: 1]</w:t></w:r></w:p></w:document>']);

		$result = $this->verifier->verify(bytes: $docx, redactedValues: [self::NAME], outputMode: RedactionOutputMode::DOCX);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::INCREMENTAL_UPDATE));
	}//end testADocxTrackedDeletionIsAnEarlierRevision()

	public function testPackagePartsAreRoutedByName(): void {
		$xlsx = $this->package(
			parts: [
				'xl/workbook.xml' => '<workbook/>',
				'xl/comments1.xml' => '<comments><comment><text><t>Bel Jan Jansen</t></text></comment></comments>',
				'docProps/core.xml' => '<cp:coreProperties><dc:creator>Jan Jansen</dc:creator></cp:coreProperties>',
				'docProps/thumbnail.jpeg' => 'jpeg bytes Jan Jansen',
				'xl/embeddings/oleObject1.bin' => 'ole Jan Jansen',
				'xl/revisions/revisionLog1.xml' => '<revisions><rcc><oc><is><t>Jan Jansen</t></is></oc></rcc></revisions>',
			]
		);

		$result = $this->verifier->verify(bytes: $xlsx, redactedValues: [self::NAME], outputMode: RedactionOutputMode::XLSX);

		self::assertSame(RedactionLeakRoute::ALL, $result['routesChecked']);
		foreach ([RedactionLeakRoute::ANNOTATIONS_AND_FIELDS, RedactionLeakRoute::EXIF, RedactionLeakRoute::EMBEDDED_PREVIEW, RedactionLeakRoute::EMBEDDED_ATTACHMENT, RedactionLeakRoute::INCREMENTAL_UPDATE, RedactionLeakRoute::TEXT_UNDER_MARK] as $route) {
			self::assertSame(1, $this->countOn(result: $result, route: $route), $route);
		}

		self::assertSame(0, $this->countOn(result: $result, route: RedactionLeakRoute::XMP));
	}//end testPackagePartsAreRoutedByName()

	public function testAWorkbookEmbeddedInADocumentIsReadInside(): void {
		$inner = $this->package(parts: ['xl/sharedStrings.xml' => '<sst><si><t>Kerkstraat 1</t></si></sst>']);
		$docx  = $this->package(parts: ['word/document.xml' => '<w:document/>', 'word/embeddings/Microsoft_Excel_Worksheet.xlsx' => $inner]);

		$result = $this->verifier->verify(bytes: $docx, redactedValues: ['Kerkstraat 1'], outputMode: RedactionOutputMode::DOCX);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::EMBEDDED_ATTACHMENT));
	}//end testAWorkbookEmbeddedInADocumentIsReadInside()

	public function testAnOdtAnnotationAndTrackedChangeAreFound(): void {
		$content = '<office:document-content><office:text><text:tracked-changes><text:changed-region><text:deletion><text:p>Kerkstraat 1</text:p></text:deletion></text:changed-region></text:tracked-changes>'
			. '<text:p><office:annotation><text:p>Jan &amp; Piet Jansen</text:p></office:annotation></text:p></office:text></office:document-content>';
		$odt     = $this->package(parts: ['content.xml' => $content, 'meta.xml' => '<office:meta/>']);

		$result = $this->verifier->verify(bytes: $odt, redactedValues: ['Jan & Piet Jansen', 'Kerkstraat 1'], outputMode: RedactionOutputMode::ODT);

		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::ANNOTATIONS_AND_FIELDS));
		self::assertSame(1, $this->countOn(result: $result, route: RedactionLeakRoute::INCREMENTAL_UPDATE));
		self::assertSame(2, $this->countOn(result: $result, route: RedactionLeakRoute::TEXT_UNDER_MARK));
	}//end testAnOdtAnnotationAndTrackedChangeAreFound()

	public function testACleanPackageIsClean(): void {
		$pptx = $this->package(parts: ['ppt/slides/slide1.xml' => '<p:sld><a:t>[PERSOON: 1]</a:t></p:sld>', 'docProps/core.xml' => '<cp:coreProperties/>']);

		$result = $this->verifier->verify(bytes: $pptx, redactedValues: [self::NAME], outputMode: RedactionOutputMode::PPTX);

		self::assertSame(RedactionIrreversibilityVerifier::CLEAN, $result['verdict']);
		self::assertSame(RedactionLeakRoute::ALL, $result['routesChecked']);
	}//end testACleanPackageIsClean()

	public function testPlainTextIsExaminedOnEveryRoute(): void {
		$clean   = $this->verifier->verify(bytes: 'Besluit voor [PERSOON: 1]', redactedValues: [self::NAME], outputMode: RedactionOutputMode::TEXT);
		$leaking = $this->verifier->verify(bytes: 'Besluit voor JAN JANSEN', redactedValues: [self::NAME], outputMode: RedactionOutputMode::TEXT);

		self::assertSame(RedactionIrreversibilityVerifier::CLEAN, $clean['verdict']);
		self::assertSame(RedactionLeakRoute::ALL, $clean['routesChecked']);
		self::assertSame(RedactionIrreversibilityVerifier::LEAKING, $leaking['verdict']);
		self::assertSame(1, $this->countOn(result: $leaking, route: RedactionLeakRoute::TEXT_UNDER_MARK));
	}//end testPlainTextIsExaminedOnEveryRoute()

	/**
	 * The count of the finding on a route, 0 when there is none.
	 *
	 * @param array<string, mixed> $result The verification result.
	 * @param string               $route  The route.
	 *
	 * @return int The count.
	 */
	private function countOn(array $result, string $route): int {
		foreach ($result['findings'] as $finding) {
			if ($finding['route'] === $route) {
				return $finding['count'];
			}
		}

		return 0;
	}//end countOn()

	/**
	 * A Flate-compressed stream object body.
	 *
	 * @param string $content The stream content.
	 * @param string $dict    Extra dictionary entries.
	 *
	 * @return string The object body.
	 */
	private function stream(string $content, string $dict = ''): string {
		$data = (string) gzcompress($content);
		return '<< ' . $dict . ' /Filter /FlateDecode /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
	}//end stream()

	/**
	 * A one-page PDF with extra objects.
	 *
	 * @param array<int, string> $objects      Object bodies by number (4 is the page content).
	 * @param string             $pageExtra    Extra page dictionary entries.
	 * @param string             $trailerExtra Extra trailer entries.
	 *
	 * @return string The PDF.
	 */
	private function pdf(array $objects, string $pageExtra = '', string $trailerExtra = ''): string {
		$all = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R ' . $pageExtra . ' >>',
		] + $objects;
		$out = "%PDF-1.7\n";
		foreach ($all as $number => $body) {
			$out .= $number . " 0 obj\n" . $body . "\nendobj\n";
		}

		return $out . 'trailer << /Root 1 0 R /Size ' . (count($all) + 1) . ' ' . $trailerExtra . " >>\nstartxref\n0\n%%EOF\n";
	}//end pdf()

	/**
	 * A ZIP package with the given parts.
	 *
	 * @param array<string, string> $parts The parts by name.
	 *
	 * @return string The package bytes.
	 */
	private function package(array $parts): string {
		$path = (string) tempnam(sys_get_temp_dir(), 'or-verify-test-');
		$zip  = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		$zip->addFromString('[Content_Types].xml', '<Types/>');
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string) file_get_contents($path);
		unlink($path);
		return $bytes;
	}//end package()
}//end class
