<?php

/**
 * MetadataMaskTest
 *
 * A value inside a descriptive metadata field is masked with the run's
 * substitution map, identity fields are still stripped, and a field that
 * cannot be masked is removed and named (REQ-RRS-004). Each format is built
 * in-test and read back part by part.
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
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use ddn\sapp\PDFDoc;
use DOMDocument;
use DOMXPath;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfInfoText;
use OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\MetadataMask;
use OCA\OpenRegister\Files\Service\File\Sanitizer\MetadataMaskingSanitizerInterface;
use OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer;
use OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Metadata masked in place, per format.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\MetadataMask
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OfficePartSurgery
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlCommonParts
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OdtSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfMetadataSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Pdf\PdfInfoText
 * @covers \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlPackage
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\DocxSanitizationRun
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\RelsPrune
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationHiddenCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts
 */
class MetadataMaskTest extends TestCase {

	private const NS_DC = 'http://purl.org/dc/elements/1.1/';

	private const NS_CP = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';

	private const NS_CUSTOM = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';

	private const NS_META = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';

	private const RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	private const SENTINEL = 'DocuDesk Anonymisation';

	private const MAP = ['Jan Jansen' => '[PERSOON: 1]'];

	/**
	 * Paths to delete after the test.
	 *
	 * @var list<string>
	 */
	private array $paths = [];

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The office formats with their fixture builder.
	 *
	 * @return array<string, array{string}>
	 */
	public static function officeFormats(): array {
		return ['docx' => ['docx'], 'odt' => ['odt'], 'xlsx' => ['xlsx'], 'pptx' => ['pptx']];
	}//end officeFormats()

	/**
	 * `Besluit op bezwaar Jan Jansen` becomes `Besluit op bezwaar [PERSOON: 1]`.
	 *
	 * @dataProvider officeFormats
	 *
	 * @param string $format The format.
	 *
	 * @return void
	 */
	public function testATitleIsMaskedNotBlanked(string $format): void {
		[$fields, $report] = $this->sanitise(format: $format, mask: new MetadataMask(substitutions: self::MAP));

		$this->assertSame('Besluit op bezwaar [PERSOON: 1]', $fields['title']);
		$this->assertSame('Bezwaar van [PERSOON: 1] tegen de vergunning', $fields['description']);
		$this->assertArrayHasKey('unmaskableFields', $report);
	}//end testATitleIsMaskedNotBlanked()

	/**
	 * The PDF /Info Title, Subject and Keywords are masked the same way,
	 * Unicode text included.
	 *
	 * @return void
	 */
	public function testATitleIsMaskedNotBlankedInAPdf(): void {
		$this->requireSapp();
		$mask = new MetadataMask(substitutions: self::MAP);
		$doc  = $this->pdfDoc(info: [
			'Title' => '(Besluit op bezwaar Jan Jansen)',
			'Subject' => '<' . strtoupper(bin2hex("\xFE\xFF" . mb_convert_encoding('Zaak Jan Jansen, café', 'UTF-16BE', 'UTF-8'))) . '>',
			'Keywords' => '(bezwaar\\, Jan Jansen)',
		]);

		(new PdfMetadataSanitizer(logger: $this->createMock(LoggerInterface::class)))->sanitize(doc: $doc, mask: $mask);
		$info = $this->pdfInfo(bytes: (string) $doc->to_pdf_file_s(rebuild: true));

		$this->assertSame('Besluit op bezwaar [PERSOON: 1]', $info['Title']);
		$this->assertSame('Zaak [PERSOON: 1], café', $info['Subject']);
		$this->assertSame('bezwaar, [PERSOON: 1]', $info['Keywords']);
		$this->assertSame([], $mask->unmaskable());
	}//end testATitleIsMaskedNotBlankedInAPdf()

	/**
	 * Author, last modified by and creator are replaced by the sentinel, as
	 * before, though the mask would only have replaced the name.
	 *
	 * @dataProvider officeFormats
	 *
	 * @param string $format The format.
	 *
	 * @return void
	 */
	public function testIdentityFieldsAreStillStripped(string $format): void {
		[$fields] = $this->sanitise(format: $format, mask: new MetadataMask(substitutions: self::MAP));

		$this->assertSame(self::SENTINEL, $fields['creator']);
		$this->assertStringNotContainsString('Jan Jansen', implode('|', $fields));
	}//end testIdentityFieldsAreStillStripped()

	/**
	 * The PDF /Info Author and Creator are stripped; provenance stays.
	 *
	 * @return void
	 */
	public function testIdentityFieldsAreStillStrippedInAPdf(): void {
		$this->requireSapp();
		$doc = $this->pdfDoc(info: ['Author' => '(Jan Jansen)', 'Creator' => '(Piet Pietersen)', 'Producer' => '(OpenRegister)']);

		(new PdfMetadataSanitizer(logger: $this->createMock(LoggerInterface::class)))
			->sanitize(doc: $doc, mask: new MetadataMask(substitutions: self::MAP));
		$info = $this->pdfInfo(bytes: (string) $doc->to_pdf_file_s(rebuild: true));

		$this->assertSame('[REDACTED]', $info['Author']);
		$this->assertSame('[REDACTED]', $info['Creator']);
		$this->assertNotSame('[REDACTED]', $info['Producer']);
	}//end testIdentityFieldsAreStillStrippedInAPdf()

	/**
	 * A binary custom property is removed and named with its reason; a text
	 * custom property is masked and kept.
	 *
	 * @dataProvider officeFormats
	 *
	 * @param string $format The format.
	 *
	 * @return void
	 */
	public function testANonTextCustomPropertyIsRemovedAndNamed(string $format): void {
		[$fields, $report] = $this->sanitise(format: $format, mask: new MetadataMask(substitutions: self::MAP));

		$this->assertArrayNotHasKey('custom:scan-origineel', $fields);
		$this->assertSame('[PERSOON: 1]', $fields['custom:Behandelaar']);
		$this->assertCount(1, $report['unmaskableFields']);
		$this->assertSame('scan-origineel', $report['unmaskableFields'][0]['field']);
		$this->assertStringStartsWith('not a text value', $report['unmaskableFields'][0]['reason']);
	}//end testANonTextCustomPropertyIsRemovedAndNamed()

	/**
	 * A PDF /Info entry that is not a string is removed and named.
	 *
	 * @return void
	 */
	public function testANonTextCustomPropertyIsRemovedAndNamedInAPdf(): void {
		$this->requireSapp();
		$mask = new MetadataMask(substitutions: self::MAP);
		$doc  = $this->pdfDoc(info: ['Behandelaar' => '(Jan Jansen)', 'Volgnummer' => '42']);

		(new PdfMetadataSanitizer(logger: $this->createMock(LoggerInterface::class)))->sanitize(doc: $doc, mask: $mask);
		$info = $this->pdfInfo(bytes: (string) $doc->to_pdf_file_s(rebuild: true));

		$this->assertSame('[PERSOON: 1]', $info['Behandelaar']);
		$this->assertArrayNotHasKey('Volgnummer', $info);
		$this->assertSame([['field' => 'Volgnummer', 'reason' => 'not a text string']], $mask->unmaskable());
	}//end testANonTextCustomPropertyIsRemovedAndNamedInAPdf()

	/**
	 * Without a mask the sanitisers behave as before: descriptive fields
	 * go to the sentinel, the binary property stays, nothing is named.
	 *
	 * @dataProvider officeFormats
	 *
	 * @param string $format The format.
	 *
	 * @return void
	 */
	public function testThePlainRunIsUnchanged(string $format): void {
		[$fields, $report] = $this->sanitise(format: $format, mask: null);

		$this->assertSame(self::SENTINEL, $fields['title']);
		$this->assertArrayNotHasKey('unmaskableFields', $report);
	}//end testThePlainRunIsUnchanged()

	/**
	 * A needle inside a longer one is not replaced twice, and the longest
	 * needle wins.
	 *
	 * @return void
	 */
	public function testTheLongestNeedleWinsAndPlaceholdersAreNotReplacedAgain(): void {
		$mask = new MetadataMask(substitutions: ['Jan Jansen' => '[PERSOON: 1]', 'Jan' => '[PERSOON: 2]', 'PERSOON' => 'x', '' => 'y']);

		$this->assertSame('[PERSOON: 1] en [PERSOON: 2]', $mask->apply(text: 'Jan Jansen en Jan'));
		$this->assertTrue($mask->isActive());
		$this->assertFalse(MetadataMask::none()->isActive());
	}//end testTheLongestNeedleWinsAndPlaceholdersAreNotReplacedAgain()

	/**
	 * Non-ASCII text is written back as a UTF-16BE hex string and read
	 * back as the same text; ASCII is written as an escaped literal.
	 *
	 * @return void
	 */
	public function testPdfInfoTextRoundTrips(): void {
		$this->requireSapp();

		$this->assertSame('(a \\(b\\) c\\\\)', (string) PdfInfoText::write(text: 'a (b) c\\'));
		$this->assertSame('Zaak café', PdfInfoText::read(value: PdfInfoText::write(text: 'Zaak café')));
		$this->assertSame("a(b)\n\x08A", PdfInfoText::read(value: new \ddn\sapp\pdfvalue\PDFValueString('a\\(b\\)\\n\\b\\101')));
		$this->assertNull(PdfInfoText::read(value: 42));
	}//end testPdfInfoTextRoundTrips()

	/**
	 * Sanitise one fixture and read its metadata fields back.
	 *
	 * @param string            $format The format.
	 * @param MetadataMask|null $mask   The mask, or null for the plain run.
	 *
	 * @return array{array<string, string>, array<string, mixed>} The fields (title, description, creator, custom:<name>) and the report.
	 */
	private function sanitise(string $format, ?MetadataMask $mask): array {
		$sanitizer = match ($format) {
			'docx' => new DocxSanitizer(sentinel: self::SENTINEL),
			'odt' => new OdtSanitizer(sentinel: self::SENTINEL),
			'xlsx' => new XlsxSanitizer(sentinel: self::SENTINEL),
			'pptx' => new PptxSanitizer(sentinel: self::SENTINEL),
		};
		$this->assertInstanceOf(MetadataMaskingSanitizerInterface::class, $sanitizer);

		$path = $this->build(format: $format);
		if ($mask === null) {
			$report = $sanitizer->sanitize($path, $path);
		} else {
			$report = $sanitizer->sanitizeMasked(sourcePath: $path, destPath: $path, mask: $mask);
		}

		$zip = new ZipArchive();
		$zip->open($path);
		$fields = $format === 'odt' ? $this->odtFields(zip: $zip) : $this->ooxmlFields(zip: $zip);
		$zip->close();

		return [$fields, $report->jsonSerialize()];
	}//end sanitise()

	/**
	 * The OOXML core and custom fields.
	 *
	 * @param ZipArchive $zip The sanitised package.
	 *
	 * @return array<string, string>
	 */
	private function ooxmlFields(ZipArchive $zip): array {
		$core = $this->dom(xml: (string) $zip->getFromName('docProps/core.xml'));
		$fields = [
			'title' => $core->getElementsByTagNameNS(self::NS_DC, 'title')->item(0)?->textContent,
			'description' => $core->getElementsByTagNameNS(self::NS_DC, 'description')->item(0)?->textContent,
			'creator' => $core->getElementsByTagNameNS(self::NS_DC, 'creator')->item(0)?->textContent,
			'lastModifiedBy' => $core->getElementsByTagNameNS(self::NS_CP, 'lastModifiedBy')->item(0)?->textContent,
		];

		$custom = $this->dom(xml: (string) $zip->getFromName('docProps/custom.xml'));
		foreach ($custom->getElementsByTagNameNS(self::NS_CUSTOM, 'property') as $property) {
			$fields['custom:' . $property->getAttribute('name')] = $property->textContent;
		}

		return array_map('strval', $fields);
	}//end ooxmlFields()

	/**
	 * The ODF meta fields.
	 *
	 * @param ZipArchive $zip The sanitised package.
	 *
	 * @return array<string, string>
	 */
	private function odtFields(ZipArchive $zip): array {
		$meta = $this->dom(xml: (string) $zip->getFromName('meta.xml'));
		$fields = [
			'title' => $meta->getElementsByTagNameNS(self::NS_DC, 'title')->item(0)?->textContent,
			'description' => $meta->getElementsByTagNameNS(self::NS_DC, 'description')->item(0)?->textContent,
			'creator' => $meta->getElementsByTagNameNS(self::NS_DC, 'creator')->item(0)?->textContent,
			'initialCreator' => $meta->getElementsByTagNameNS(self::NS_META, 'initial-creator')->item(0)?->textContent,
		];
		foreach ($meta->getElementsByTagNameNS(self::NS_META, 'user-defined') as $field) {
			$fields['custom:' . $field->getAttributeNS(self::NS_META, 'name')] = $field->textContent;
		}

		return array_map('strval', $fields);
	}//end odtFields()

	/**
	 * Load XML.
	 *
	 * @param string $xml The XML.
	 *
	 * @return DOMDocument
	 */
	private function dom(string $xml): DOMDocument {
		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml));
		return $dom;
	}//end dom()

	/**
	 * Build the fixture of one format.
	 *
	 * @param string $format The format.
	 *
	 * @return string The path.
	 */
	private function build(string $format): string {
		$parts = match ($format) {
			'odt' => $this->odtParts(),
			'docx' => $this->docxParts(),
			'xlsx' => $this->xlsxParts(),
			'pptx' => $this->pptxParts(),
		};

		$path = tempnam(sys_get_temp_dir(), 'mask_') . '.' . $format;
		$this->paths[] = $path;
		$zip = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($parts as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();
		return $path;
	}//end build()

	/**
	 * The parts every OOXML fixture shares: core and custom properties.
	 *
	 * @param string $mainPart The main part's name.
	 *
	 * @return array<string, string>
	 */
	private function ooxmlCommonParts(string $mainPart): array {
		$rel = static fn (string $id, string $type, string $target): string => '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . $target . '"/>';

		return [
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/>'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Override PartName="/' . $mainPart . '" ContentType="application/xml"/></Types>',
			'_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. $rel('rId1', self::RELS . '/officeDocument', $mainPart)
				. $rel('rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml')
				. $rel('rId3', self::RELS . '/custom-properties', 'docProps/custom.xml')
				. '</Relationships>',
			'docProps/core.xml' => '<?xml version="1.0"?><cp:coreProperties xmlns:cp="' . self::NS_CP . '" xmlns:dc="' . self::NS_DC . '">'
				. '<dc:creator>Jan Jansen</dc:creator><cp:lastModifiedBy>Jan Jansen</cp:lastModifiedBy>'
				. '<dc:title>Besluit op bezwaar Jan Jansen</dc:title>'
				. '<dc:description>Bezwaar van Jan Jansen tegen de vergunning</dc:description></cp:coreProperties>',
			'docProps/custom.xml' => '<?xml version="1.0"?><Properties xmlns="' . self::NS_CUSTOM . '" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
				. '<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="2" name="Behandelaar"><vt:lpwstr>Jan Jansen</vt:lpwstr></property>'
				. '<property fmtid="{D5CDD505-2E9C-101B-9397-08002B2CF9AE}" pid="3" name="scan-origineel"><vt:blob>SmFuIEphbnNlbg==</vt:blob></property>'
				. '</Properties>',
		];
	}//end ooxmlCommonParts()

	/**
	 * A one-paragraph document.
	 *
	 * @return array<string, string>
	 */
	private function docxParts(): array {
		return $this->ooxmlCommonParts(mainPart: 'word/document.xml') + [
			'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>body</w:t></w:r></w:p></w:body></w:document>',
			'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
		];
	}//end docxParts()

	/**
	 * A one-sheet workbook.
	 *
	 * @return array<string, string>
	 */
	private function xlsxParts(): array {
		$ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . self::RELS . '"';
		return $this->ooxmlCommonParts(mainPart: 'xl/workbook.xml') + [
			'xl/workbook.xml' => '<?xml version="1.0"?><workbook ' . $ns . '><sheets><sheet name="Blad1" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="' . self::RELS . '/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
			'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData/></worksheet>',
		];
	}//end xlsxParts()

	/**
	 * A one-slide presentation.
	 *
	 * @return array<string, string>
	 */
	private function pptxParts(): array {
		$ns = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="' . self::RELS . '"';
		return $this->ooxmlCommonParts(mainPart: 'ppt/presentation.xml') + [
			'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation ' . $ns . '><p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst></p:presentation>',
			'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="' . self::RELS . '/slide" Target="slides/slide1.xml"/></Relationships>',
			'ppt/slides/slide1.xml' => '<?xml version="1.0"?><p:sld ' . $ns . '><p:cSld/></p:sld>',
		];
	}//end pptxParts()

	/**
	 * A one-paragraph ODF text with descriptive, identity and user-defined fields.
	 *
	 * @return array<string, string>
	 */
	private function odtParts(): array {
		$ns = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:meta="' . self::NS_META . '" xmlns:dc="' . self::NS_DC . '"';
		return [
			'mimetype' => 'application/vnd.oasis.opendocument.text',
			'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
				. '<office:body><office:text><text:p>body</text:p></office:text></office:body></office:document-content>',
			'meta.xml' => '<?xml version="1.0"?><office:document-meta ' . $ns . '><office:meta>'
				. '<dc:creator>Jan Jansen</dc:creator><meta:initial-creator>Jan Jansen</meta:initial-creator>'
				. '<dc:title>Besluit op bezwaar Jan Jansen</dc:title>'
				. '<dc:description>Bezwaar van Jan Jansen tegen de vergunning</dc:description>'
				. '<meta:user-defined meta:name="Behandelaar" meta:value-type="string">Jan Jansen</meta:user-defined>'
				. '<meta:user-defined meta:name="scan-origineel" meta:value-type="float">19700101</meta:user-defined>'
				. '</office:meta></office:document-meta>',
		];
	}//end odtParts()

	/**
	 * Skip when the PDF library is absent, as the other PDF tests do.
	 *
	 * @return void
	 */
	private function requireSapp(): void {
		if (class_exists(PDFDoc::class) === false) {
			$this->markTestSkipped('ddn/sapp (PDFDoc) is not installed in this environment.');
		}
	}//end requireSapp()

	/**
	 * A one-page PDF with the given raw /Info entries.
	 *
	 * @param array<string, string> $info Key to raw PDF value (`(text)`, `<hex>`, `42`).
	 *
	 * @return PDFDoc
	 */
	private function pdfDoc(array $info): PDFDoc {
		$entries = '';
		foreach ($info as $key => $raw) {
			$entries .= ' /' . $key . ' ' . $raw;
		}

		$objects = [
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
			3 => '<< /Type /Page /Parent 2 0 R /MediaBox [ 0 0 612 792 ] >>',
			4 => '<<' . $entries . ' >>',
		];
		$pdf     = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $oid => $body) {
			$offsets[$oid] = strlen($pdf);
			$pdf          .= $oid . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= "xref\n0 5\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf("%010d 00000 n \n", $offset);
		}

		$pdf .= "trailer\n<< /Size 5 /Root 1 0 R /Info 4 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";

		$doc = PDFDoc::from_string(buffer: $pdf);
		$this->assertNotFalse($doc);
		return $doc;
	}//end pdfDoc()

	/**
	 * The /Info entries of serialised PDF bytes, read as text.
	 *
	 * @param string $bytes The PDF.
	 *
	 * @return array<string, string|null>
	 */
	private function pdfInfo(string $bytes): array {
		$doc = PDFDoc::from_string(buffer: $bytes);
		$this->assertNotFalse($doc);
		$trailer = (new \ReflectionProperty(PDFDoc::class, '_pdf_trailer_object'))->getValue($doc);
		$info    = $doc->get_object($trailer['Info']->get_object_referenced())->get_value();
		$out     = [];
		foreach ($info->get_keys() as $key) {
			$out[(string) $key] = PdfInfoText::read(value: $info[$key]);
		}

		return $out;
	}//end pdfInfo()
}//end class
