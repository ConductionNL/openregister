<?php

declare(strict_types=1);

/**
 * DocumentExtractor Unit Tests
 *
 * Every document here is built inside the test with ZipArchive from
 * hand-written WordprocessingML parts, so each structure the spec names is
 * present on purpose: headings of two levels, a localised heading style id, a
 * style chain with a cycle, text before the first heading, a title paragraph
 * and a core properties title, runs split mid-word, a text box stored twice
 * for compatibility, a tracked deletion, bulleted, numbered and style-numbered
 * lists, a table with a nested table, embedded, linked and VML pictures, a
 * strict OOXML package, and hostile parts (a DOCTYPE, deep nesting, a document
 * past the block cap). One document follows the shape LibreOffice 24.2 writes:
 * heading styles that carry outline numbering with the number format `none`,
 * a `TextBody` body style, direct list numbering, a text frame stored as
 * `mc:AlternateContent` with its text in both branches, and an anchored
 * picture. No fixture file is committed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use Exception;
use OCA\OpenRegister\Service\TextExtraction\DocumentBodyParser;
use OCA\OpenRegister\Service\TextExtraction\DocumentContentReader;
use OCA\OpenRegister\Service\TextExtraction\DocumentExtractor;
use OCA\OpenRegister\Service\TextExtraction\DocumentStyleMap;
use OCA\OpenRegister\Service\TextExtraction\WordExtractor;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Unit tests for DocumentExtractor, DocumentBodyParser, DocumentContentReader, DocumentStyleMap and OoxmlElements.
 */
#[RequiresPhpExtension('zip')]
class DocumentExtractorTest extends TestCase {

	private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	private const NS = 'xmlns:w="' . self::W_NS . '" '
		. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
		. 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
		. 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
		. 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
		. 'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
		. 'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" '
		. 'xmlns:v="urn:schemas-microsoft-com:vml" '
		. 'xmlns:o="urn:schemas-microsoft-com:office:office" '
		. 'mc:Ignorable="wps"';

	private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

	private const REL_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

	private const CORE_TYPE = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties';

	/** A valid 1x1 PNG: PhpWord, which WordExtractor uses, refuses a picture part that is not a real image. */
	private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNg+M8AAAICAQB7CYF4AAAAAElFTkSuQmCC';

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var WordExtractor&MockObject */
	private WordExtractor $wordExtractor;

	private DocumentExtractor $extractor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->wordExtractor = $this->createMock(WordExtractor::class);
		$this->wordExtractor->method('extract')->willReturn('flat text from the word extractor');
		$this->extractor = new DocumentExtractor(logger: $this->logger, wordExtractor: $this->wordExtractor);
	}

	// ------------------------------------------------------------------
	// Document building
	// ------------------------------------------------------------------

	/**
	 * Zip the given parts into package bytes.
	 *
	 * @param array<string, string> $parts Part path to content.
	 *
	 * @return string The package bytes.
	 */
	private function zip(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'docx-test-');
		$zip = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);

		return $bytes;
	}

	/**
	 * A relationships part.
	 *
	 * @param array<string, array{0: string, 1: string, 2?: bool}> $relationships Id to [type suffix or full type, target, external].
	 *
	 * @return string
	 */
	private function rels(array $relationships): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::REL_NS . '">';
		foreach ($relationships as $id => $relationship) {
			$type = $relationship[0];
			if (str_contains($type, '://') === false) {
				$type = self::REL_TYPE . $type;
			}

			$mode = '';
			if (($relationship[2] ?? false) === true) {
				$mode = ' TargetMode="External"';
			}

			$xml .= '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . htmlspecialchars($relationship[1], ENT_XML1) . '"' . $mode . '/>';
		}

		return $xml . '</Relationships>';
	}

	/**
	 * A complete package around the given body.
	 *
	 * @param string $body The w:body children.
	 * @param array<string, string> $parts Extra parts, e.g. `word/styles.xml`.
	 * @param array<string, array{0: string, 1: string, 2?: bool}> $documentRels The document part's relationships.
	 * @param string|null $coreTitle A core properties title, or null for no core part.
	 *
	 * @return string The package bytes.
	 */
	private function docx(string $body, array $parts = [], array $documentRels = [], ?string $coreTitle = null): string {
		$packageRels = ['rId1' => ['officeDocument', 'word/document.xml']];
		if ($coreTitle !== null) {
			$packageRels['rId2'] = [self::CORE_TYPE, 'docProps/core.xml'];
			$parts['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
				. 'xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . htmlspecialchars($coreTitle, ENT_XML1) . '</dc:title></cp:coreProperties>';
		}

		return $this->zip(
			array_merge(
				[
					'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
						. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
						. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
						. '<Default Extension="xml" ContentType="application/xml"/>'
						. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
						. '</Types>',
					'_rels/.rels' => $this->rels($packageRels),
					'word/document.xml' => $this->document(body: $body),
					'word/_rels/document.xml.rels' => $this->rels($documentRels),
				],
				$parts
			)
		);
	}

	/**
	 * A document part around the given body.
	 *
	 * @param string $body The w:body children.
	 *
	 * @return string
	 */
	private function document(string $body): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . self::NS . '><w:body>'
			. $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/></w:sectPr></w:body></w:document>';
	}

	/**
	 * A run with the given text.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function textRun(string $text): string {
		return '<w:r><w:rPr><w:lang w:val="nl-NL"/></w:rPr><w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r>';
	}

	/**
	 * A paragraph holding the given runs.
	 *
	 * @param string $content The runs (or any inline content).
	 * @param string $style The paragraph style id, '' for none.
	 * @param string $properties Extra w:pPr children.
	 *
	 * @return string
	 */
	private function paragraph(string $content, string $style = '', string $properties = ''): string {
		$pPr = '';
		if ($style !== '' || $properties !== '') {
			$styleElement = '';
			if ($style !== '') {
				$styleElement = '<w:pStyle w:val="' . $style . '"/>';
			}

			$pPr = '<w:pPr>' . $styleElement . $properties . '</w:pPr>';
		}

		return '<w:p>' . $pPr . $content . '</w:p>';
	}

	/**
	 * A one-run paragraph.
	 *
	 * @param string $text The text.
	 * @param string $style The paragraph style id, '' for none.
	 * @param string $properties Extra w:pPr children.
	 *
	 * @return string
	 */
	private function p(string $text, string $style = '', string $properties = ''): string {
		return $this->paragraph(content: $this->textRun(text: $text), style: $style, properties: $properties);
	}

	/**
	 * A numbering reference for a paragraph.
	 *
	 * @param int $numId The numbering instance id.
	 * @param int $ilvl The 0-based list level.
	 *
	 * @return string
	 */
	private function numPr(int $numId, int $ilvl = 0): string {
		return '<w:numPr><w:ilvl w:val="' . $ilvl . '"/><w:numId w:val="' . $numId . '"/></w:numPr>';
	}

	/**
	 * A styles part.
	 *
	 * @param list<array{0: string, 1: string, 2?: string, 3?: string}> $styles Each [id, name, basedOn, pPr children].
	 *
	 * @return string
	 */
	private function styles(array $styles): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="' . self::W_NS . '">'
			. '<w:docDefaults><w:rPrDefault><w:rPr><w:lang w:val="nl-NL"/></w:rPr></w:rPrDefault></w:docDefaults>'
			. '<w:style w:type="character" w:styleId="Strong"><w:name w:val="heading 1"/></w:style>';
		foreach ($styles as $style) {
			$basedOn = '';
			if (($style[2] ?? '') !== '') {
				$basedOn = '<w:basedOn w:val="' . $style[2] . '"/>';
			}

			$xml .= '<w:style w:type="paragraph" w:styleId="' . $style[0] . '"><w:name w:val="' . $style[1] . '"/>' . $basedOn
				. '<w:qFormat/><w:pPr>' . ($style[3] ?? '') . '</w:pPr></w:style>';
		}

		return $xml . '</w:styles>';
	}

	/**
	 * A numbering part.
	 *
	 * @param array<int, array<int, string>> $abstracts Abstract id to [level to number format].
	 * @param array<int, int> $instances Numbering instance id to abstract id.
	 *
	 * @return string
	 */
	private function numbering(array $abstracts, array $instances): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="' . self::W_NS . '">';
		foreach ($abstracts as $abstractId => $levels) {
			$xml .= '<w:abstractNum w:abstractNumId="' . $abstractId . '"><w:multiLevelType w:val="hybridMultilevel"/>';
			foreach ($levels as $level => $format) {
				$xml .= '<w:lvl w:ilvl="' . $level . '"><w:start w:val="1"/><w:numFmt w:val="' . $format . '"/><w:lvlText w:val="%1."/>'
					. '<w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>';
			}

			$xml .= '</w:abstractNum>';
		}

		foreach ($instances as $numId => $abstractId) {
			$xml .= '<w:num w:numId="' . $numId . '"><w:abstractNumId w:val="' . $abstractId . '"/></w:num>';
		}

		return $xml . '</w:numbering>';
	}

	/**
	 * A run holding a DrawingML picture.
	 *
	 * @param string $name The picture name.
	 * @param string $description The alt text.
	 * @param string $blipAttribute E.g. `r:embed="rId5"`.
	 * @param string $placement `inline` or `anchor`.
	 *
	 * @return string
	 */
	private function drawing(string $name, string $description, string $blipAttribute, string $placement = 'inline'): string {
		return '<w:r><w:drawing><wp:' . $placement . '><wp:extent cx="952500" cy="952500"/>'
			. '<wp:docPr id="1" name="' . $name . '" descr="' . $description . '"/>'
			. '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
			. '<pic:nvPicPr><pic:cNvPr id="0" name="' . $name . '.png"/><pic:cNvPicPr/></pic:nvPicPr>'
			. '<pic:blipFill><a:blip ' . $blipAttribute . '/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr/>'
			. '</pic:pic></a:graphicData></a:graphic></wp:' . $placement . '></w:drawing></w:r>';
	}

	/**
	 * A run holding a text box stored twice: as a modern shape and as its VML fallback.
	 *
	 * @param string $text The text box's text.
	 * @param string $style The style of the paragraph inside the text box.
	 *
	 * @return string
	 */
	private function textBox(string $text, string $style = ''): string {
		$content = '<w:txbxContent>' . $this->p(text: $text, style: $style) . '</w:txbxContent>';

		return '<w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing><wp:anchor><wp:docPr id="2" name="Frame1"/>'
			. '<a:graphic><a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"><wps:wsp>'
			. '<wps:spPr/><wps:txbx>' . $content . '</wps:txbx><wps:bodyPr/></wps:wsp></a:graphicData></a:graphic></wp:anchor>'
			. '</w:drawing></mc:Choice><mc:Fallback><w:pict><v:rect><v:textbox>' . $content . '</v:textbox></v:rect></w:pict>'
			. '</mc:Fallback></mc:AlternateContent></w:r>';
	}

	/**
	 * A table with the given rows of cell content.
	 *
	 * @param list<list<string>> $rows Each row as a list of cell contents (block XML).
	 *
	 * @return string
	 */
	private function table(array $rows): string {
		$xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr><w:tblGrid><w:gridCol w:w="4819"/><w:gridCol w:w="4819"/></w:tblGrid>';
		foreach ($rows as $cells) {
			$xml .= '<w:tr>';
			foreach ($cells as $cell) {
				$xml .= '<w:tc><w:tcPr><w:tcW w:w="4819" w:type="dxa"/></w:tcPr>' . $cell . '</w:tc>';
			}

			$xml .= '</w:tr>';
		}

		return $xml . '</w:tbl>';
	}

	/**
	 * A mocked Nextcloud file.
	 *
	 * @param string $content The bytes.
	 * @param string $mime The MIME type.
	 * @param string $name The file name.
	 *
	 * @return File&MockObject
	 */
	private function mockFile(string $content, string $mime = self::DOCX_MIME, string $name = 'les-3.docx'): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(404);

		return $file;
	}

	/**
	 * Extract a package and assert there is a result.
	 *
	 * @param string $bytes The package bytes.
	 *
	 * @return array<string, mixed>
	 */
	private function extract(string $bytes): array {
		$result = $this->extractor->extract(file: $this->mockFile(content: $bytes));
		$this->assertIsArray($result);

		return $result;
	}

	/**
	 * The heading and level of every section.
	 *
	 * @param array<string, mixed> $result The extraction result.
	 *
	 * @return list<array{0: string, 1: int}>
	 */
	private function headings(array $result): array {
		return array_map(static fn (array $section): array => [$section['heading'], $section['level']], $result['sections']);
	}

	/**
	 * Every block of every section, in order.
	 *
	 * @param array<string, mixed> $result The extraction result.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function blocks(array $result): array {
		$blocks = [];
		foreach ($result['sections'] as $section) {
			array_push($blocks, ...$section['blocks']);
		}

		return $blocks;
	}

	/**
	 * The texts of every paragraph block, in order.
	 *
	 * @param array<string, mixed> $result The extraction result.
	 *
	 * @return list<string>
	 */
	private function paragraphTexts(array $result): array {
		$texts = [];
		foreach ($this->blocks($result) as $block) {
			if ($block['type'] === 'paragraph') {
				$texts[] = $block['text'];
			}
		}

		return $texts;
	}

	/**
	 * The lesson document in the shape LibreOffice 24.2 writes it.
	 *
	 * @return string The package bytes.
	 */
	private function libreOfficeDocument(): string {
		$heading = static fn (int $level): string => '<w:numPr><w:ilvl w:val="' . ($level - 1) . '"/><w:numId w:val="1"/></w:numPr>'
			. '<w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="' . ($level - 1) . '"/>';

		$styles = $this->styles(
			[
				['Normal', 'Normal', '', '<w:widowControl/>'],
				['Heading', 'Heading', 'Normal', '<w:keepNext w:val="true"/><w:spacing w:before="240" w:after="120"/>'],
				['Heading1', 'heading 1', 'Heading', $heading(1)],
				['Heading2', 'heading 2', 'Heading', $heading(2)],
				['Heading3', 'heading 3', 'Heading', $heading(3)],
				['TextBody', 'Body Text', 'Normal', '<w:spacing w:before="0" w:after="140" w:line="276" w:lineRule="auto"/>'],
				['Title', 'Title', 'Heading', '<w:jc w:val="center"/>'],
				['TableContents', 'Table Contents', 'Normal', '<w:suppressLineNumbers/>'],
				['FrameContents', 'Frame Contents', 'Normal', ''],
			]
		);
		$numbering = $this->numbering(
			[
				1 => [0 => 'none', 1 => 'none', 2 => 'none'],
				2 => [0 => 'bullet', 1 => 'bullet'],
				3 => [0 => 'decimal'],
			],
			[1 => 1, 2 => 2, 3 => 3]
		);

		$body = $this->p(text: 'Water in de klas', style: 'Title')
			. $this->p(text: 'Fotosynthese', style: 'Heading1', properties: $this->numPr(numId: 1))
			. $this->paragraph(content: $this->textRun(text: 'Planten maken ') . '<w:r><w:rPr><w:b/></w:rPr><w:t>voedsel</w:t></w:r>' . $this->textRun(text: ' uit licht.'), style: 'TextBody')
			. $this->p(text: 'Wat heb je nodig', style: 'Heading2')
			. $this->p(text: 'Licht', style: 'TextBody', properties: $this->numPr(numId: 2))
			. $this->p(text: 'Water', style: 'TextBody', properties: $this->numPr(numId: 2))
			. $this->p(text: 'Uit de grond', style: 'TextBody', properties: $this->numPr(numId: 2, ilvl: 1))
			. $this->p(text: 'Eerst kijken', style: 'TextBody', properties: $this->numPr(numId: 3))
			. $this->p(text: 'Dan meten', style: 'TextBody', properties: $this->numPr(numId: 3))
			. $this->table(
				[
					[$this->p(text: 'Stof', style: 'TableContents'), $this->p(text: 'Rol', style: 'TableContents')],
					[$this->p(text: 'CO2', style: 'TableContents'), $this->p(text: 'Bouwstof', style: 'TableContents')],
				]
			)
			. $this->paragraph(
				content: $this->textBox(text: 'Let op: niet in de zon', style: 'FrameContents')
					. $this->drawing(name: 'Image1', description: 'Een blad in de zon', blipAttribute: 'r:embed="rId3"', placement: 'anchor'),
				style: 'TextBody'
			)
			. $this->p(text: 'Proef', style: 'Heading3')
			. $this->p(text: 'Zet de plant in het licht.', style: 'TextBody');

		return $this->docx(
			body: $body,
			parts: ['word/styles.xml' => $styles, 'word/numbering.xml' => $numbering, 'word/media/image1.png' => base64_decode(self::PNG_1PX)],
			documentRels: [
				'rId1' => ['styles', 'styles.xml'],
				'rId2' => ['numbering', 'numbering.xml'],
				'rId3' => ['image', 'media/image1.png'],
			],
			coreTitle: 'Fotosynthese les'
		);
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-001: sections under their heading
	// ------------------------------------------------------------------

	/**
	 * Two headings of different levels each open a section holding what follows them.
	 *
	 * @return void
	 */
	public function testTwoHeadingsOfDifferentLevelsEachOpenASection(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Fotosynthese', style: 'Heading1') . $this->p(text: 'Planten maken voedsel')
					. $this->p(text: 'Proef', style: 'Heading2') . $this->p(text: 'Zet de plant in het licht'),
				parts: ['word/styles.xml' => $this->styles([['Heading1', 'heading 1'], ['Heading2', 'heading 2']])]
			)
		);

		$this->assertSame([['Fotosynthese', 1], ['Proef', 2]], $this->headings($result));
		$this->assertSame([['type' => 'paragraph', 'text' => 'Planten maken voedsel']], $result['sections'][0]['blocks']);
		$this->assertSame([['type' => 'paragraph', 'text' => 'Zet de plant in het licht']], $result['sections'][1]['blocks']);
	}

	/**
	 * A localised style id is recognised by the style's name.
	 *
	 * @return void
	 */
	public function testALocalisedHeadingStyleIsRecognisedByItsName(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Inleiding', style: 'Kop1') . $this->p(text: 'Tekst'),
				parts: ['word/styles.xml' => $this->styles([['Kop1', 'heading 1']])]
			)
		);

		$this->assertSame([['Inleiding', 1]], $this->headings($result));
	}

	/**
	 * Text before the first heading sits in a first section with no heading and level 0.
	 *
	 * @return void
	 */
	public function testTextBeforeTheFirstHeadingIsKept(): void {
		$result = $this->extract($this->docx(body: $this->p(text: 'Groep 6, week 12') . $this->p(text: 'Water', style: 'Heading1')));

		$this->assertSame([['', 0], ['Water', 1]], $this->headings($result));
		$this->assertSame([['type' => 'paragraph', 'text' => 'Groep 6, week 12']], $result['sections'][0]['blocks']);
		$this->assertSame([], $result['sections'][1]['blocks']);
	}

	/**
	 * Outline levels on the paragraph and on styles, inherited through basedOn, set the level; outline 9 is body text.
	 *
	 * @return void
	 */
	public function testOutlineLevelsAndTheBasedOnChainSetTheLevel(): void {
		$styles = $this->styles(
			[
				['Heading2', 'heading 2'],
				['MijnKop', 'Mijn kop', 'Heading2'],
				['Opsomming', 'Opsomming', '', '<w:outlineLvl w:val="3"/>'],
				['Gewoon', 'Gewoon', 'Heading2', '<w:outlineLvl w:val="9"/>'],
			]
		);
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Via de keten', style: 'MijnKop')
					. $this->p(text: 'Via de stijl', style: 'Opsomming')
					. $this->p(text: 'Direct', properties: '<w:outlineLvl w:val="2"/>')
					. $this->p(text: 'Direct body', style: 'Heading2', properties: '<w:outlineLvl w:val="9"/>')
					. $this->p(text: 'Stijl body', style: 'Gewoon'),
				parts: ['word/styles.xml' => $styles]
			)
		);

		$this->assertSame([['Via de keten', 2], ['Via de stijl', 4], ['Direct', 3]], $this->headings($result));
		$this->assertSame(['Direct body', 'Stijl body'], $this->paragraphTexts($result));
	}

	/**
	 * Without a styles part, the English style ids still mark headings and the title.
	 *
	 * @return void
	 */
	public function testHeadingStyleIdsWorkWithoutAStylesPart(): void {
		$result = $this->extract($this->docx(body: $this->p(text: 'Les', style: 'Title') . $this->p(text: 'Doel', style: 'Heading3')));

		$this->assertSame('Les', $result['title']);
		$this->assertSame([['Doel', 3]], $this->headings($result));
	}

	/**
	 * A style chain that loops ends without hanging, and the paragraph is text.
	 *
	 * @return void
	 */
	public function testAStyleChainThatLoopsIsSafe(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Rondje', style: 'A'),
				parts: ['word/styles.xml' => $this->styles([['A', 'Stijl a', 'B'], ['B', 'Stijl b', 'A']])]
			)
		);

		$this->assertSame(['Rondje'], $this->paragraphTexts($result));
	}

	/**
	 * A basedOn chain is followed up to MAX_STYLE_CHAIN steps and no further.
	 *
	 * @return void
	 */
	public function testAStyleChainIsFollowedOnlyUpToItsCap(): void {
		// Style 0 is based on 1, 1 on 2, and so on; only the last one is a heading.
		$build = static function (int $length): array {
			$styles = [];
			for ($index = 0; $index < $length; $index++) {
				$styles[] = ['S' . $index, 'Stijl ' . $index, 'S' . ($index + 1)];
			}

			$styles[] = ['S' . $length, 'heading 2'];
			return $styles;
		};

		$within = $this->extract(
			$this->docx(
				body: $this->p(text: 'Binnen de grens', style: 'S0'),
				parts: ['word/styles.xml' => $this->styles($build(DocumentStyleMap::MAX_STYLE_CHAIN - 1))]
			)
		);
		$beyond = $this->extract(
			$this->docx(
				body: $this->p(text: 'Voorbij de grens', style: 'S0'),
				parts: ['word/styles.xml' => $this->styles($build(DocumentStyleMap::MAX_STYLE_CHAIN))]
			)
		);

		$this->assertSame([['Binnen de grens', 2]], $this->headings($within));
		$this->assertSame(['Voorbij de grens'], $this->paragraphTexts($beyond));
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-002: title
	// ------------------------------------------------------------------

	/**
	 * The first Title paragraph names the document and is not a block; a later one opens a section.
	 *
	 * @return void
	 */
	public function testATitleParagraphNamesTheDocument(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Water in de klas', style: 'Title') . $this->p(text: 'Intro') . $this->p(text: 'Deel twee', style: 'Title'),
				parts: ['word/styles.xml' => $this->styles([['Title', 'Title']])],
				coreTitle: 'Oude titel'
			)
		);

		$this->assertSame('Water in de klas', $result['title']);
		$this->assertSame(['Intro'], $this->paragraphTexts($result));
		$this->assertSame([['', 0], ['Deel twee', 1]], $this->headings($result));
	}

	/**
	 * Without a Title paragraph the core properties title is used.
	 *
	 * @return void
	 */
	public function testTheCorePropertiesTitleIsTheFallback(): void {
		$result = $this->extract($this->docx(body: $this->p(text: 'Tekst'), coreTitle: 'Les 4'));

		$this->assertSame('Les 4', $result['title']);
	}

	/**
	 * A document with neither has an empty title.
	 *
	 * @return void
	 */
	public function testADocumentWithoutATitleHasAnEmptyTitle(): void {
		$result = $this->extract($this->docx(body: $this->p(text: 'Tekst')));

		$this->assertSame('', $result['title']);
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-003: paragraph text
	// ------------------------------------------------------------------

	/**
	 * Runs are joined into one string; tabs and breaks become spaces; whitespace collapses; empty paragraphs vanish.
	 *
	 * @return void
	 */
	public function testAParagraphSplitIntoRunsIsOneString(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->paragraph(content: $this->textRun(text: 'Water ') . $this->textRun(text: 'kookt'))
					. $this->paragraph(content: '<w:r><w:t>Stap</w:t><w:tab/><w:t>een</w:t><w:br/><w:t>twee</w:t><w:noBreakHyphen/><w:t>drie</w:t></w:r>')
					. $this->paragraph(content: '<w:r><w:t xml:space="preserve">   </w:t></w:r>')
					. $this->paragraph(content: '<w:hyperlink r:id="rId9"><w:r><w:t>een link</w:t></w:r></w:hyperlink>')
			)
		);

		$this->assertSame(['Water kookt', 'Stap een twee-drie', 'een link'], $this->paragraphTexts($result));
	}

	/**
	 * A text box stored in a modern shape and its compatibility fallback is read once, after its paragraph.
	 *
	 * @return void
	 */
	public function testATextBoxStoredTwiceIsReadOnce(): void {
		$result = $this->extract(
			$this->docx(body: $this->paragraph(content: $this->textRun(text: 'Voor') . $this->textBox(text: 'Let op')) . $this->p(text: 'Na'))
		);

		$this->assertSame(['Voor', 'Let op', 'Na'], $this->paragraphTexts($result));
	}

	/**
	 * Deleted text of a tracked change is not read; inserted text is.
	 *
	 * @return void
	 */
	public function testDeletedTextIsLeftOut(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->paragraph(
					content: $this->textRun(text: 'Nu')
						. '<w:del w:id="1" w:author="Juf"><w:r><w:delText>Straks</w:delText></w:r></w:del>'
				)
				. $this->paragraph(content: $this->textRun(text: 'Wel') . '<w:ins w:id="2" w:author="Juf">' . $this->textRun(text: ' erbij') . '</w:ins>')
				. $this->paragraph(content: '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>PAGE</w:instrText></w:r><w:r><w:t>7</w:t></w:r>')
			)
		);

		$this->assertSame(['Nu', 'Wel erbij', '7'], $this->paragraphTexts($result));
	}

	/**
	 * Content controls and custom XML wrappers are walked in place.
	 *
	 * @return void
	 */
	public function testContentControlsAreWalkedInPlace(): void {
		$result = $this->extract(
			$this->docx(
				body: '<w:sdt><w:sdtPr/><w:sdtContent>' . $this->p(text: 'In een besturingselement') . '</w:sdtContent></w:sdt>'
					. '<w:customXml w:element="les">' . $this->p(text: 'In custom XML') . '</w:customXml>'
					. $this->paragraph(content: '<w:sdt><w:sdtContent>' . $this->textRun(text: 'Inline veld') . '</w:sdtContent></w:sdt>')
			)
		);

		$this->assertSame(['In een besturingselement', 'In custom XML', 'Inline veld'], $this->paragraphTexts($result));
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-004: lists
	// ------------------------------------------------------------------

	/**
	 * A bulleted list with a nested item is one list block with levels.
	 *
	 * @return void
	 */
	public function testABulletedListWithANestedItem(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Licht', properties: $this->numPr(numId: 5)) . $this->p(text: 'Water', properties: $this->numPr(numId: 5))
					. '<w:p/>'
					. $this->p(text: 'Uit de grond', properties: $this->numPr(numId: 5, ilvl: 1)),
				parts: ['word/numbering.xml' => $this->numbering([7 => [0 => 'bullet', 1 => 'bullet']], [5 => 7])]
			)
		);

		$this->assertSame(
			[
				[
					'type' => 'list',
					'items' => [
						['text' => 'Licht', 'level' => 1, 'ordered' => false],
						['text' => 'Water', 'level' => 1, 'ordered' => false],
						['text' => 'Uit de grond', 'level' => 2, 'ordered' => false],
					],
				],
			],
			$this->blocks($result)
		);
	}

	/**
	 * A numbered list is ordered; a new numbering id starts a new list; numbering id 0 is plain text.
	 *
	 * @return void
	 */
	public function testANumberedListIsOrderedAndANewNumberingStartsANewList(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Eerst kijken', properties: $this->numPr(numId: 1)) . $this->p(text: 'Dan meten', properties: $this->numPr(numId: 1))
					. $this->p(text: 'Los punt', properties: $this->numPr(numId: 2))
					. $this->p(text: 'Geen lijst', properties: $this->numPr(numId: 0))
					. $this->p(text: 'Onbekend', properties: $this->numPr(numId: 99)),
				parts: ['word/numbering.xml' => $this->numbering([1 => [0 => 'decimal'], 2 => [0 => 'lowerLetter']], [1 => 1, 2 => 2])]
			)
		);

		$blocks = $this->blocks($result);
		$this->assertCount(4, $blocks);
		$this->assertSame([['text' => 'Eerst kijken', 'level' => 1, 'ordered' => true], ['text' => 'Dan meten', 'level' => 1, 'ordered' => true]], $blocks[0]['items']);
		$this->assertSame([['text' => 'Los punt', 'level' => 1, 'ordered' => true]], $blocks[1]['items']);
		$this->assertSame(['type' => 'paragraph', 'text' => 'Geen lijst'], $blocks[2]);
		$this->assertSame([['text' => 'Onbekend', 'level' => 1, 'ordered' => false]], $blocks[3]['items']);
	}

	/**
	 * Numbering carried by the paragraph style (Word's List Bullet) makes a list item.
	 *
	 * @return void
	 */
	public function testNumberingFromTheStyleMakesAListItem(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Via de stijl', style: 'ListBullet') . $this->p(text: 'Dieper', style: 'ListBullet2'),
				parts: [
					'word/styles.xml' => $this->styles(
						[
							['ListBullet', 'List Bullet', '', $this->numPr(numId: 3)],
							['ListBullet2', 'List Bullet 2', 'ListBullet', '<w:numPr><w:ilvl w:val="1"/></w:numPr>'],
						]
					),
					'word/numbering.xml' => $this->numbering([4 => [0 => 'bullet', 1 => 'decimal']], [3 => 4]),
				]
			)
		);

		$this->assertSame(
			[['text' => 'Via de stijl', 'level' => 1, 'ordered' => false], ['text' => 'Dieper', 'level' => 2, 'ordered' => true]],
			$this->blocks($result)[0]['items']
		);
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-005: tables
	// ------------------------------------------------------------------

	/**
	 * A table comes back as rows of cell text, in place.
	 *
	 * @return void
	 */
	public function testATableComesBackAsRowsOfCellText(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Voor')
					. $this->table([[$this->p(text: 'Stof'), $this->p(text: 'Rol')], [$this->p(text: 'CO2'), $this->p(text: 'Bouwstof')]])
					. $this->p(text: 'Na')
			)
		);

		$this->assertSame(
			[
				['type' => 'paragraph', 'text' => 'Voor'],
				['type' => 'table', 'rows' => [['Stof', 'Rol'], ['CO2', 'Bouwstof']]],
				['type' => 'paragraph', 'text' => 'Na'],
			],
			$this->blocks($result)
		);
	}

	/**
	 * A cell's paragraphs join with a newline and a nested table adds its text to the cell; pictures follow the table.
	 *
	 * @return void
	 */
	public function testACellJoinsItsParagraphsAndANestedTable(): void {
		$nested = $this->table([[$this->p(text: 'Binnen A'), $this->p(text: 'Binnen B')]]);
		$result = $this->extract(
			$this->docx(
				body: $this->table(
					[
						[
							$this->p(text: 'Regel 1') . '<w:p/>' . $this->p(text: 'Regel 2'),
							$nested . '<w:sdt><w:sdtContent>' . $this->p(text: 'Veld') . '</w:sdtContent></w:sdt>',
						],
						[$this->paragraph(content: $this->drawing(name: 'Cel', description: 'In de cel', blipAttribute: 'r:embed="rId4"')), ''],
					]
				),
				documentRels: ['rId4' => ['image', 'media/cel.png']]
			)
		);

		$blocks = $this->blocks($result);
		$this->assertSame(['type' => 'table', 'rows' => [["Regel 1\nRegel 2", "Binnen A\nBinnen B\nVeld"], ['', '']]], $blocks[0]);
		$this->assertSame('word/media/cel.png', $blocks[1]['target']);
		$this->assertCount(2, $blocks);
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-006: image references
	// ------------------------------------------------------------------

	/**
	 * An embedded picture is referenced by its package path and alt text, after its paragraph.
	 *
	 * @return void
	 */
	public function testAnEmbeddedPictureIsReferencedByItsPackagePathAndAltText(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->paragraph(content: $this->textRun(text: 'Kijk') . $this->drawing(name: 'Blad', description: 'Een blad in de zon', blipAttribute: 'r:embed="rId5"')),
				documentRels: ['rId5' => ['image', 'media/image1.png']]
			)
		);

		$this->assertSame(
			[
				['type' => 'paragraph', 'text' => 'Kijk'],
				['type' => 'image', 'target' => 'word/media/image1.png', 'external' => false, 'name' => 'Blad', 'description' => 'Een blad in de zon'],
			],
			$this->blocks($result)
		);
	}

	/**
	 * A linked picture keeps its URL and is flagged external.
	 *
	 * @return void
	 */
	public function testALinkedPictureIsFlaggedExternal(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->paragraph(content: $this->drawing(name: 'Web', description: '', blipAttribute: 'r:link="rId6"')),
				documentRels: ['rId6' => ['image', 'https://example.org/blad.png', true]]
			)
		);

		$this->assertSame(
			[['type' => 'image', 'target' => 'https://example.org/blad.png', 'external' => true, 'name' => 'Web', 'description' => '']],
			$this->blocks($result)
		);
	}

	/**
	 * A legacy VML picture is referenced with its title and alt text; one outside the package is external.
	 *
	 * @return void
	 */
	public function testALegacyVmlPictureIsReferenced(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->paragraph(content: '<w:r><w:pict><v:shape alt="Oude plaat"><v:imagedata r:id="rId7" o:title="Schets"/></v:shape></w:pict></w:r>')
					. $this->paragraph(content: '<w:r><w:pict><v:shape><v:imagedata r:id="rId8"/></v:shape></w:pict></w:r>'),
				documentRels: ['rId7' => ['image', 'media/image2.wmf'], 'rId8' => ['image', '../../buiten.png']]
			)
		);

		$this->assertSame(
			[
				['type' => 'image', 'target' => 'word/media/image2.wmf', 'external' => false, 'name' => 'Schets', 'description' => 'Oude plaat'],
				['type' => 'image', 'target' => '../../buiten.png', 'external' => true, 'name' => '', 'description' => ''],
			],
			$this->blocks($result)
		);
	}

	/**
	 * A picture whose relationship is missing keeps its place with an empty target.
	 *
	 * @return void
	 */
	public function testAPictureWithAMissingRelationshipKeepsItsPlace(): void {
		$result = $this->extract($this->docx(body: $this->paragraph(content: $this->drawing(name: 'Weg', description: 'Kwijt', blipAttribute: 'r:embed="rId404"'))));

		$this->assertSame([['type' => 'image', 'target' => '', 'external' => false, 'name' => 'Weg', 'description' => 'Kwijt']], $this->blocks($result));
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-007: flat text
	// ------------------------------------------------------------------

	/**
	 * The flat text is exactly what WordExtractor returns for the same file.
	 *
	 * @return void
	 */
	public function testTheFlatTextEqualsWhatSearchIndexes(): void {
		$wordExtractor = new WordExtractor(logger: $this->logger);
		$extractor = new DocumentExtractor(logger: $this->logger, wordExtractor: $wordExtractor);
		$file = $this->mockFile(content: $this->libreOfficeDocument());

		$flat = $wordExtractor->extract(file: $file);
		$result = $extractor->extract(file: $file);

		$this->assertIsString($flat);
		$this->assertStringContainsString('Fotosynthese', $flat);
		$this->assertIsArray($result);
		$this->assertSame($flat, $result['text']);
	}

	/**
	 * When WordExtractor gives nothing, the structure fills in the flat text, one line per entry.
	 *
	 * @return void
	 */
	public function testTheStructureFillsInWhenTheFlatTextIsEmpty(): void {
		$wordExtractor = $this->createMock(WordExtractor::class);
		$wordExtractor->method('extract')->willReturn(null);
		$extractor = new DocumentExtractor(logger: $this->logger, wordExtractor: $wordExtractor);

		$result = $extractor->extract(file: $this->mockFile(content: $this->libreOfficeDocument()));

		$this->assertIsArray($result);
		$this->assertSame(
			"Water in de klas\nFotosynthese\nPlanten maken voedsel uit licht.\nWat heb je nodig\nLicht\nWater\nUit de grond\n"
			. "Eerst kijken\nDan meten\nStof\tRol\nCO2\tBouwstof\nLet op: niet in de zon\nProef\nZet de plant in het licht.",
			$result['text']
		);
	}

	/**
	 * When WordExtractor throws (PhpWord missing), the structure still comes back with its own flat text.
	 *
	 * @return void
	 */
	public function testAMissingFlatTextLibraryDoesNotBlockTheStructure(): void {
		$wordExtractor = $this->createMock(WordExtractor::class);
		$wordExtractor->method('extract')->willThrowException(new Exception('PhpWord library (phpoffice/phpword) is not installed.'));
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('[DocumentExtractor] Flat text unavailable'), $this->callback(static fn (array $context): bool => $context['exception'] === Exception::class));
		$extractor = new DocumentExtractor(logger: $this->logger, wordExtractor: $wordExtractor);

		$result = $extractor->extract(file: $this->mockFile(content: $this->docx(body: $this->p(text: 'Alleen dit'))));

		$this->assertIsArray($result);
		$this->assertSame('Alleen dit', $result['text']);
	}

	// ------------------------------------------------------------------
	// LibreOffice-shaped document
	// ------------------------------------------------------------------

	/**
	 * LibreOffice's numbered headings stay headings, its text frame is read once, and its picture keeps its alt text.
	 *
	 * @return void
	 */
	public function testALibreOfficeDocumentReadsAsTheTeacherWroteIt(): void {
		$result = $this->extract($this->libreOfficeDocument());

		$this->assertSame('Water in de klas', $result['title']);
		$this->assertSame([['Fotosynthese', 1], ['Wat heb je nodig', 2], ['Proef', 3]], $this->headings($result));
		$this->assertSame([['type' => 'paragraph', 'text' => 'Planten maken voedsel uit licht.']], $result['sections'][0]['blocks']);
		$this->assertSame(
			[
				[
					'type' => 'list',
					'items' => [
						['text' => 'Licht', 'level' => 1, 'ordered' => false],
						['text' => 'Water', 'level' => 1, 'ordered' => false],
						['text' => 'Uit de grond', 'level' => 2, 'ordered' => false],
					],
				],
				['type' => 'list', 'items' => [['text' => 'Eerst kijken', 'level' => 1, 'ordered' => true], ['text' => 'Dan meten', 'level' => 1, 'ordered' => true]]],
				['type' => 'table', 'rows' => [['Stof', 'Rol'], ['CO2', 'Bouwstof']]],
				['type' => 'image', 'target' => 'word/media/image1.png', 'external' => false, 'name' => 'Image1', 'description' => 'Een blad in de zon'],
				['type' => 'paragraph', 'text' => 'Let op: niet in de zon'],
			],
			$result['sections'][1]['blocks']
		);
		$this->assertSame([['type' => 'paragraph', 'text' => 'Zet de plant in het licht.']], $result['sections'][2]['blocks']);
		$this->assertFalse($result['truncated']);
	}

	/**
	 * A strict OOXML package (other namespaces, same local names) reads the same way.
	 *
	 * @return void
	 */
	public function testAStrictOoxmlPackageReadsTheSame(): void {
		$strict = str_replace(
			[self::W_NS, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'],
			['http://purl.oclc.org/ooxml/wordprocessingml/main', 'http://purl.oclc.org/ooxml/officeDocument/relationships'],
			$this->document(body: $this->p(text: 'Strikt', style: 'Heading1') . $this->paragraph(content: $this->drawing(name: 'S', description: '', blipAttribute: 'r:embed="rId5"')))
		);
		$bytes = $this->zip(
			[
				'_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="' . self::REL_NS . '"><Relationship Id="rId1" '
					. 'Type="http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
				'word/document.xml' => $strict,
				'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="' . self::REL_NS . '"><Relationship Id="rId5" '
					. 'Type="http://purl.oclc.org/ooxml/officeDocument/relationships/image" Target="media/s.png"/></Relationships>',
			]
		);

		$result = $this->extract($bytes);

		$this->assertSame([['Strikt', 1]], $this->headings($result));
		$this->assertSame('word/media/s.png', $this->blocks($result)[0]['target']);
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-008: graceful failure
	// ------------------------------------------------------------------

	/**
	 * Garbage bytes return null and the error log carries no part of the bytes.
	 *
	 * @return void
	 */
	public function testGarbageBytesReturnNullWithoutLeakingContent(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('[DocumentExtractor] Document extraction failed'),
				$this->callback(
					static function (array $context): bool {
						return str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'GEHEIM') === false
							&& $context['fileId'] === 404
							&& $context['mimeType'] === self::DOCX_MIME;
					}
				)
			);
		$this->wordExtractor->expects($this->never())->method('extract');

		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: 'GEHEIM-12345 this is not a zip package')));
	}

	/**
	 * A legacy binary document is not read, and its bytes are never fetched.
	 *
	 * @return void
	 */
	public function testALegacyDocIsNotRead(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/msword');
		$file->method('getName')->willReturn('les-3.doc');
		$file->method('getId')->willReturn(404);
		$file->expects($this->never())->method('getContent');

		$this->assertNull($this->extractor->extract(file: $file));
	}

	/**
	 * A zip without a document part returns null.
	 *
	 * @return void
	 */
	public function testAPackageWithoutADocumentPartReturnsNull(): void {
		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: $this->zip(['readme.txt' => 'geen document']))));
	}

	/**
	 * A document part without a body returns null.
	 *
	 * @return void
	 */
	public function testADocumentPartWithoutABodyReturnsNull(): void {
		$bytes = $this->zip(['word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . self::W_NS . '"/>']);

		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: $bytes)));
	}

	/**
	 * A document with no text and no pictures returns null and says so, with the MIME type.
	 *
	 * @return void
	 */
	public function testAnEmptyDocumentReturnsNull(): void {
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('holds no readable content'), $this->callback(static fn (array $context): bool => $context['mimeType'] === self::DOCX_MIME));

		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: $this->docx(body: '<w:p/><w:p><w:r><w:t> </w:t></w:r></w:p>'))));
	}

	/**
	 * The zip extension is present in this suite, so the guard lets extraction through.
	 *
	 * @return void
	 */
	public function testTheZipGuardPassesWhenTheExtensionIsLoaded(): void {
		$this->assertTrue(class_exists(ZipArchive::class));
		$this->assertIsArray($this->extractor->extract(file: $this->mockFile(content: $this->docx(body: $this->p(text: 'Ja')))));
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-009: bounds
	// ------------------------------------------------------------------

	/**
	 * A DOCTYPE in the document part is refused (no entity expanded) and the part name is logged.
	 *
	 * @return void
	 */
	public function testADoctypeInTheDocumentPartIsRefused(): void {
		$hostile = '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY boom "BOEM">]><w:document xmlns:w="' . self::W_NS . '">'
			. '<w:body><w:p><w:r><w:t>&boom;</w:t></w:r></w:p></w:body></w:document>';
		$bytes = $this->zip(
			[
				'_rels/.rels' => $this->rels(['rId1' => ['officeDocument', 'word/document.xml']]),
				'word/document.xml' => $hostile,
			]
		);

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: $bytes)));
		$this->assertSame(['word/document.xml'], $warnings[0][1]['parts']);
		$this->assertStringNotContainsString('BOEM', json_encode($warnings, JSON_THROW_ON_ERROR));
	}

	/**
	 * A refused styles part costs the heading names, not the body.
	 *
	 * @return void
	 */
	public function testARefusedStylesPartStillReadsTheBody(): void {
		$result = $this->extract(
			$this->docx(
				body: $this->p(text: 'Kop', style: 'Kop1') . $this->p(text: 'Tekst'),
				parts: ['word/styles.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><w:styles xmlns:w="' . self::W_NS . '"/>']
			)
		);

		$this->assertSame(['Kop', 'Tekst'], $this->paragraphTexts($result));
	}

	/**
	 * Past MAX_BLOCKS the reading stops and the result says it was truncated.
	 *
	 * @return void
	 */
	public function testADocumentPastTheBlockCapIsTruncated(): void {
		$body = str_repeat('<w:p><w:r><w:t>x</w:t></w:r></w:p>', (DocumentBodyParser::MAX_BLOCKS + 1));

		$result = $this->extract($this->docx(body: $body));

		$this->assertTrue($result['truncated']);
		$this->assertCount(DocumentBodyParser::MAX_BLOCKS, $this->paragraphTexts($result));
	}

	/**
	 * A document within the limits is not truncated.
	 *
	 * @return void
	 */
	public function testADocumentWithinTheLimitsIsNotTruncated(): void {
		$result = $this->extract($this->docx(body: $this->p(text: 'Een') . $this->p(text: 'Twee') . $this->p(text: 'Drie')));

		$this->assertFalse($result['truncated']);
	}

	/**
	 * Content controls nested past MAX_DEPTH stop the descent without failing the document.
	 *
	 * @return void
	 */
	public function testDeepNestingIsBounded(): void {
		$depth = (DocumentContentReader::MAX_DEPTH + 5);
		$body = '';
		for ($level = 1; $level <= $depth; $level++) {
			$text = '';
			if ($level === 3) {
				$text = $this->p(text: 'Ondiep');
			}

			$body .= '<w:sdt><w:sdtContent>' . $text;
		}

		$body .= $this->p(text: 'Te diep') . str_repeat('</w:sdtContent></w:sdt>', $depth);

		$result = $this->extract($this->docx(body: $body));

		$this->assertSame(['Ondiep'], $this->paragraphTexts($result));
	}

	// ------------------------------------------------------------------
	// REQ-DOCX-010: supported formats
	// ------------------------------------------------------------------

	/**
	 * Which files the extractor says it reads.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function formatProvider(): array {
		return [
			'docx mime' => [self::DOCX_MIME, 'les.docx', true],
			'docm mime, mixed case' => ['application/vnd.ms-word.document.macroEnabled.12', 'les.docm', true],
			'dotx mime' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'les.dotx', true],
			'dotm mime' => ['application/vnd.ms-word.template.macroenabled.12', 'les.dotm', true],
			'generic mime, docx extension' => ['application/octet-stream', 'les-3.docx', true],
			'zip mime, DOCX extension' => ['application/zip', 'LES-3.DOCX', true],
			'generic mime, pptx extension' => ['application/octet-stream', 'les-3.pptx', false],
			'legacy doc' => ['application/msword', 'les-3.doc', false],
			'opendocument text' => ['application/vnd.oasis.opendocument.text', 'les-3.odt', false],
			'rtf named docx' => ['text/rtf', 'les-3.docx', false],
		];
	}

	/**
	 * Supported formats are recognised by MIME type, or by extension when the MIME type is generic.
	 *
	 * @param string $mimeType The MIME type.
	 * @param string $fileName The file name.
	 * @param bool $expected Whether the extractor reads it.
	 *
	 * @return void
	 */
	#[DataProvider('formatProvider')]
	public function testSupportedFormats(string $mimeType, string $fileName, bool $expected): void {
		$this->assertSame($expected, $this->extractor->supports(mimeType: $mimeType, fileName: $fileName));
	}
}//end class
