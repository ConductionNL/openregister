<?php

/**
 * XlsxSanitizerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer}.
 * A workbook is built in-test with a visible sheet and a hidden sheet, a
 * hidden row and a hidden column holding names, comments, a revision log,
 * custom XML and an author in its metadata; the sanitised package is read
 * back part by part, and searched whole for every name that must not leave.
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
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */

declare(strict_types=1);

namespace Unit\Service\File\Sanitizer;

use OCA\OpenRegister\Exception\SanitizationException;
use OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * A hidden sheet, row or column, a comment or a revision does not go out.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\XlsxSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlPackage
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlCommonParts
 * @covers \OCA\OpenRegister\Files\Service\File\SanitizationHiddenCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OfficePartSurgery
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts
 */
class XlsxSanitizerTest extends TestCase {

	private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	private const NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

	private const RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * Paths to delete after the test.
	 *
	 * @var list<string>
	 */
	private array $paths = [];

	/**
	 * The sanitised package's parts, by name.
	 *
	 * @var array<string, string>
	 */
	private array $out = [];

	/**
	 * The report of the run.
	 *
	 * @var array<string, int|string>
	 */
	private array $report = [];

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}
	}//end tearDown()

	/**
	 * Sanitise the fixture workbook and read the result back.
	 *
	 * @return void
	 */
	private function sanitiseFixture(): void {
		$source = $this->build(parts: $this->fixture());
		$dest = tempnam(sys_get_temp_dir(), 'xlsx_out_') . '.xlsx';
		$this->paths[] = $dest;

		$this->report = (new XlsxSanitizer(sentinel: 'Geanonimiseerd'))->sanitize($source, $dest)->jsonSerialize();

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($dest), 'the output is not a zip package');
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$this->out[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
		}

		$zip->close();
	}//end sanitiseFixture()

	/**
	 * Everything in the output, as one string, to search for what must not be there.
	 *
	 * @return string
	 */
	private function everything(): string {
		return implode("\n", $this->out);
	}//end everything()

	public function testItSupportsXlsxOnly(): void {
		$sanitizer = new XlsxSanitizer();

		$this->assertTrue($sanitizer->supports(self::MIME));
		$this->assertFalse($sanitizer->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
	}

	public function testAHiddenSheetIsRemoved(): void {
		$this->sanitiseFixture();

		$this->assertArrayNotHasKey('xl/worksheets/sheet2.xml', $this->out);
		$this->assertArrayNotHasKey('xl/worksheets/_rels/sheet2.xml.rels', $this->out);
		$this->assertArrayNotHasKey('xl/drawings/drawing2.xml', $this->out, 'a part only the hidden sheet used stayed');
		$this->assertStringNotContainsString('berekening-intern', $this->everything());
		$this->assertStringNotContainsString('interne berekening', $this->everything());
		$this->assertStringNotContainsString('sheet2.xml', $this->out['[Content_Types].xml']);
		$this->assertStringNotContainsString('sheet2.xml', $this->out['xl/_rels/workbook.xml.rels']);
		$this->assertStringContainsString('name="Openbaar"', $this->out['xl/workbook.xml']);
		$this->assertStringContainsString('<definedName name="Totaal">Openbaar!$A$1</definedName>', $this->out['xl/workbook.xml']);
		$this->assertStringContainsString('<definedName name="Lokaal" localSheetId="0">', $this->out['xl/workbook.xml'], 'the visible sheet keeps its local name');
		$this->assertSame(1, $this->report['hiddenSheetsRemoved']);
	}

	public function testAHiddenRowIsCleared(): void {
		$this->sanitiseFixture();

		$sheet = $this->out['xl/worksheets/sheet1.xml'];
		$this->assertStringContainsString('<row r="2" hidden="1"/>', $sheet, 'the hidden row keeps its place, without cells');
		$this->assertStringNotContainsString('r="C1"', $sheet, 'a cell in the hidden column stayed');
		$this->assertStringContainsString('<c r="A1" t="s"><v>0</v></c>', $sheet);
		$this->assertStringNotContainsString('Jan Jansen', $this->everything());
		$this->assertStringNotContainsString('kolom geheim', $this->everything());
		$this->assertStringContainsString('<si><t>Openbare tekst</t></si>', $this->out['xl/sharedStrings.xml'], 'a string still in use was emptied');
		$this->assertSame(4, substr_count($this->out['xl/sharedStrings.xml'], '<si>'), 'the string indexes moved');
		$this->assertSame(1, $this->report['hiddenRowsCleared']);
		$this->assertSame(1, $this->report['hiddenColumnsCleared']);
	}

	public function testCommentsAndRevisionsAreRemoved(): void {
		$this->sanitiseFixture();

		foreach (['xl/comments1.xml', 'xl/drawings/vmlDrawing1.vml', 'xl/threadedComments/threadedComment1.xml', 'xl/persons/person.xml', 'xl/revisions/revisionHeaders.xml', 'xl/revisions/revisionLog1.xml', 'xl/calcChain.xml', 'customXml/item1.xml'] as $part) {
			$this->assertArrayNotHasKey($part, $this->out, $part . ' stayed');
		}

		$this->assertStringNotContainsString('legacyDrawing', $this->out['xl/worksheets/sheet1.xml']);
		$this->assertStringNotContainsString('Piet Pietersen', $this->everything());
		$this->assertStringNotContainsString('Klopt dit bedrag', $this->everything());
		$this->assertStringContainsString('<dc:creator>Geanonimiseerd</dc:creator>', $this->out['docProps/core.xml']);
		$this->assertSame(2, $this->report['commentsRemoved']);
		$this->assertSame(1, $this->report['trackedChangesDropped']);
		$this->assertSame(1, $this->report['customXmlPartsDropped']);
		$this->assertSame(1, $this->report['metadataFieldsScrubbed']);
		$this->assertSame(0, $this->report['hiddenSlidesRemoved']);
	}

	public function testAPackageWithoutContentTypesIsRefusedAsEncrypted(): void {
		$source = $this->build(parts: ['EncryptedPackage' => 'x']);

		$this->expectException(SanitizationException::class);
		(new XlsxSanitizer())->sanitize($source, $source);
	}

	/**
	 * Zip the given parts into a temporary file.
	 *
	 * @param array<string, string> $parts The parts.
	 *
	 * @return string The path.
	 */
	private function build(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';
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
	 * The fixture workbook.
	 *
	 * @return array<string, string>
	 */
	private function fixture(): array {
		$rel = static fn (string $id, string $type, string $target): string => '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . $target . '"/>';
		$rels = static fn (string ...$items): string => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $items) . '</Relationships>';
		$override = static fn (string $part): string => '<Override PartName="/' . $part . '" ContentType="application/xml"/>';

		return [
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/>'
				. $override('xl/workbook.xml') . $override('xl/worksheets/sheet1.xml') . $override('xl/worksheets/sheet2.xml') . $override('xl/comments1.xml') . $override('xl/calcChain.xml') . '</Types>',
			'_rels/.rels' => $rels($rel('rId1', self::RELS . '/officeDocument', 'xl/workbook.xml'), $rel('rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml')),
			'docProps/core.xml' => '<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Piet Pietersen</dc:creator></cp:coreProperties>',
			'xl/workbook.xml' => '<?xml version="1.0"?><workbook ' . self::NS . '><bookViews><workbookView activeTab="1"/></bookViews><sheets>'
				. '<sheet name="Openbaar" sheetId="1" r:id="rId1"/><sheet name="berekening-intern" sheetId="2" state="hidden" r:id="rId2"/></sheets><definedNames>'
				. '<definedName name="Totaal">Openbaar!$A$1</definedName><definedName name="Intern">\'berekening-intern\'!$A$1</definedName>'
				. '<definedName name="Lokaal" localSheetId="0">Openbaar!$A$1</definedName><definedName name="Verborgen" localSheetId="1">$A$1</definedName></definedNames></workbook>',
			'xl/_rels/workbook.xml.rels' => $rels(
				$rel('rId1', self::RELS . '/worksheet', 'worksheets/sheet1.xml'),
				$rel('rId2', self::RELS . '/worksheet', 'worksheets/sheet2.xml'),
				$rel('rId3', self::RELS . '/sharedStrings', 'sharedStrings.xml'),
				$rel('rId4', self::RELS . '/calcChain', 'calcChain.xml'),
				$rel('rId5', self::RELS . '/revisionHeaders', 'revisions/revisionHeaders.xml'),
				$rel('rId6', 'http://schemas.microsoft.com/office/2017/10/relationships/person', 'persons/person.xml'),
				$rel('rId7', self::RELS . '/customXml', '../customXml/item1.xml')
			),
			'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet ' . self::NS . '><cols><col min="3" max="3" hidden="1"/></cols><sheetData>'
				. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>3</v></c></row>'
				. '<row r="2" hidden="1"><c r="A2" t="s"><v>1</v></c></row></sheetData><legacyDrawing r:id="rId2"/></worksheet>',
			'xl/worksheets/_rels/sheet1.xml.rels' => $rels(
				$rel('rId1', self::RELS . '/comments', '../comments1.xml'),
				$rel('rId2', self::RELS . '/vmlDrawing', '../drawings/vmlDrawing1.vml'),
				$rel('rId3', 'http://schemas.microsoft.com/office/2017/10/relationships/threadedComment', '../threadedComments/threadedComment1.xml')
			),
			'xl/worksheets/sheet2.xml' => '<?xml version="1.0"?><worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="s"><v>2</v></c></row></sheetData></worksheet>',
			'xl/worksheets/_rels/sheet2.xml.rels' => $rels($rel('rId1', self::RELS . '/drawing', '../drawings/drawing2.xml')),
			'xl/drawings/drawing2.xml' => '<?xml version="1.0"?><drawing>interne berekening</drawing>',
			'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst ' . self::NS . ' count="4" uniqueCount="4"><si><t>Openbare tekst</t></si><si><t>Jan Jansen</t></si><si><t>interne berekening</t></si><si><t>kolom geheim</t></si></sst>',
			'xl/comments1.xml' => '<?xml version="1.0"?><comments ' . self::NS . '><authors><author>Piet Pietersen</author></authors><commentList><comment ref="A1" authorId="0"><text><t>Klopt dit bedrag</t></text></comment><comment ref="A2" authorId="0"><text><t>Ja</t></text></comment></commentList></comments>',
			'xl/drawings/vmlDrawing1.vml' => '<xml>Klopt dit bedrag</xml>',
			'xl/threadedComments/threadedComment1.xml' => '<?xml version="1.0"?><ThreadedComments><threadedComment><text>Klopt dit bedrag</text></threadedComment></ThreadedComments>',
			'xl/persons/person.xml' => '<?xml version="1.0"?><personList><person displayName="Piet Pietersen"/></personList>',
			'xl/calcChain.xml' => '<?xml version="1.0"?><calcChain ' . self::NS . '><c r="A1" i="2"/></calcChain>',
			'xl/revisions/revisionHeaders.xml' => '<?xml version="1.0"?><headers><header userName="Piet Pietersen"/></headers>',
			'xl/revisions/_rels/revisionHeaders.xml.rels' => $rels($rel('rId1', self::RELS . '/revisionLog', 'revisionLog1.xml')),
			'xl/revisions/revisionLog1.xml' => '<?xml version="1.0"?><revisions><rcc><nc>Jan Jansen</nc></rcc></revisions>',
			'customXml/item1.xml' => '<?xml version="1.0"?><data>Jan Jansen</data>',
		];
	}//end fixture()
}//end class
