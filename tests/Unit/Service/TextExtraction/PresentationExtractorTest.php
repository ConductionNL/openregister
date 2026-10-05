<?php

declare(strict_types=1);

/**
 * PresentationExtractor Unit Tests
 *
 * Every deck here is built inside the test with ZipArchive from hand-written
 * PresentationML parts, so each structure the spec names is present on purpose:
 * slide files stored out of deck order, a hidden slide, title and centred-title
 * placeholders, a slide-number placeholder, runs split mid-sentence, a group, a
 * table, a markup-compatibility branch, a notes page with furniture around the
 * notes body, an embedded and a linked picture, and hostile parts (a DOCTYPE in
 * UTF-8 and in UTF-16, an oversized part, deep group nesting, a target that
 * climbs out of the package). No fixture file is committed.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\TextExtraction
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://www.OpenRegister.nl
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TextExtraction;

use OCA\OpenRegister\Service\TextExtraction\OoxmlPackage;
use OCA\OpenRegister\Service\TextExtraction\PresentationExtractor;
use OCA\OpenRegister\Service\TextExtraction\PresentationSlideParser;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Unit tests for PresentationExtractor, OoxmlPackage and PresentationSlideParser.
 */
#[RequiresPhpExtension('zip')]
class PresentationExtractorTest extends TestCase {

	private const PPTX_MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

	private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
		. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
		. 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

	private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

	private const REL_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private PresentationExtractor $extractor;

	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->extractor = new PresentationExtractor(logger: $this->logger);
	}

	// ------------------------------------------------------------------
	// Deck building
	// ------------------------------------------------------------------

	/**
	 * Zip the given parts into package bytes.
	 *
	 * @param array<string, string> $parts Part path to content.
	 *
	 * @return string The package bytes.
	 */
	private function zip(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'pptx-test-');
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
	 * @param array<string, array{0: string, 1: string, 2?: bool}> $relationships Id to [type suffix, target, external].
	 *
	 * @return string
	 */
	private function rels(array $relationships): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::REL_NS . '">';
		foreach ($relationships as $id => $relationship) {
			$mode = '';
			if (($relationship[2] ?? false) === true) {
				$mode = ' TargetMode="External"';
			}

			$xml .= '<Relationship Id="' . $id . '" Type="' . self::REL_TYPE . $relationship[0] . '" Target="' . $relationship[1] . '"' . $mode . '/>';
		}

		return $xml . '</Relationships>';
	}

	/**
	 * A slide part around the given shapes.
	 *
	 * @param string $shapes The spTree children.
	 * @param string $rootAttributes Extra attributes on p:sld (e.g. show="0").
	 *
	 * @return string
	 */
	private function slide(string $shapes, string $rootAttributes = ''): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld ' . self::NS . ' ' . $rootAttributes . '>'
			. '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
			. $shapes . '</p:spTree></p:cSld></p:sld>';
	}

	/**
	 * A text shape, optionally a placeholder of the given type, with the given paragraphs of runs.
	 *
	 * @param string|null $placeholder Placeholder type, '' for an untyped placeholder, null for a plain text box.
	 * @param array<int, array<int, string>> $paragraphs Each paragraph as a list of run texts.
	 *
	 * @return string
	 */
	private function textShape(?string $placeholder, array $paragraphs): string {
		$ph = '';
		if ($placeholder !== null) {
			$ph = '<p:ph' . ($placeholder === '' ? '' : ' type="' . $placeholder . '"') . '/>';
		}

		$body = '';
		foreach ($paragraphs as $runs) {
			$body .= '<a:p>';
			foreach ($runs as $run) {
				$body .= '<a:r><a:rPr lang="nl-NL"/><a:t>' . htmlspecialchars($run, ENT_XML1) . '</a:t></a:r>';
			}

			$body .= '</a:p>';
		}

		return '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Shape"/><p:cNvSpPr/><p:nvPr>' . $ph . '</p:nvPr></p:nvSpPr>'
			. '<p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/>' . $body . '</p:txBody></p:sp>';
	}

	/**
	 * A picture shape.
	 *
	 * @param string $name The picture name.
	 * @param string $description The alt text.
	 * @param string $relationshipAttribute E.g. `r:embed="rId2"`.
	 *
	 * @return string
	 */
	private function picture(string $name, string $description, string $relationshipAttribute): string {
		return '<p:pic><p:nvPicPr><p:cNvPr id="4" name="' . $name . '" descr="' . $description . '"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'
			. '<p:blipFill><a:blip ' . $relationshipAttribute . '/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr/></p:pic>';
	}

	/**
	 * A presentation part listing the given relationship ids in deck order, plus its package wiring.
	 *
	 * @param list<string> $slideRelationshipIds The r:id of each sldId, in deck order.
	 *
	 * @return array<string, string> The package-level parts.
	 */
	private function presentation(array $slideRelationshipIds): array {
		$list = '';
		foreach ($slideRelationshipIds as $index => $id) {
			$list .= '<p:sldId id="' . (256 + $index) . '" r:id="' . $id . '"/>';
		}

		return [
			'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'_rels/.rels' => $this->rels(['rId1' => ['officeDocument', 'ppt/presentation.xml']]),
			'ppt/presentation.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation ' . self::NS . '>'
				. '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId9"/></p:sldMasterIdLst>'
				. '<p:sldIdLst>' . $list . '</p:sldIdLst><p:sldSz cx="9144000" cy="6858000"/></p:presentation>',
		];
	}

	/**
	 * The lesson deck: slide files stored out of deck order, notes, pictures, groups, a table, a hidden slide.
	 *
	 * @return string The package bytes.
	 */
	private function lessonDeck(): string {
		$parts = $this->presentation(['rId2', 'rId1', 'rId3']);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels(
			[
				'rId1' => ['slide', 'slides/slide1.xml'],
				'rId2' => ['slide', 'slides/slide2.xml'],
				'rId3' => ['slide', 'slides/slide3.xml'],
				'rId9' => ['slideMaster', 'slideMasters/slideMaster1.xml'],
			]
		);

		// First in the deck, stored as slide2.xml.
		$parts['ppt/slides/slide2.xml'] = $this->slide(
			$this->textShape('title', [['Fotosynthese']])
			. $this->textShape('', [['Planten maken voedsel'], [], ['Licht, ', 'water en CO2']])
			. $this->textShape('sldNum', [['1']])
			. $this->picture('Blad', 'Een blad in de zon', 'r:embed="rId2"')
			. $this->picture('Zon', '', 'r:link="rId3"')
		);
		$parts['ppt/slides/_rels/slide2.xml.rels'] = $this->rels(
			[
				'rId1' => ['slideLayout', '../slideLayouts/slideLayout1.xml'],
				'rId2' => ['image', '../media/image1.png'],
				'rId3' => ['image', 'https://example.org/zon.png', true],
				'rId4' => ['notesSlide', '../notesSlides/notesSlide1.xml'],
			]
		);
		$parts['ppt/notesSlides/notesSlide1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notes ' . self::NS . '>'
			. '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
			. $this->textShape('sldImg', [])
			. $this->textShape('body', [['Vraag eerst wat ze al weten.'], ['Laat ze daarna ', 'tekenen.']])
			. $this->textShape('sldNum', [['1']])
			. '</p:spTree></p:cSld></p:notes>';
		$parts['ppt/media/image1.png'] = 'PNG-BYTES-NOT-READ';

		// Second in the deck, stored as slide1.xml: a centred title, a group, a table, a compatibility branch.
		$parts['ppt/slides/slide1.xml'] = $this->slide(
			$this->textShape('ctrTitle', [['Water ', 'kookt']])
			. $this->textShape(null, [['Eerst']])
			. '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="5" name="Groep"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
			. $this->textShape(null, [['In de groep']]) . '</p:grpSp>'
			. '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="6" name="Tabel"/><p:cNvGraphicFramePr/><p:nvPr/></p:nvGraphicFramePr>'
			. '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table"><a:tbl><a:tr h="370840">'
			. '<a:tc><a:txBody><a:bodyPr/><a:p><a:r><a:t>Cel A</a:t></a:r></a:p></a:txBody></a:tc>'
			. '<a:tc><a:txBody><a:bodyPr/><a:p><a:r><a:t>Cel B</a:t></a:r></a:p></a:txBody></a:tc>'
			. '</a:tr></a:tbl></a:graphicData></a:graphic></p:graphicFrame>'
			. '<mc:AlternateContent xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006">'
			. '<mc:Choice Requires="p14">' . $this->textShape(null, [['Keuze']]) . '</mc:Choice>'
			. '<mc:Fallback>' . $this->textShape(null, [['Terugval']]) . '</mc:Fallback></mc:AlternateContent>'
		);
		$parts['ppt/slides/_rels/slide1.xml.rels'] = $this->rels(['rId1' => ['slideLayout', '../slideLayouts/slideLayout1.xml']]);

		// Third in the deck, hidden.
		$parts['ppt/slides/slide3.xml'] = $this->slide($this->textShape('', [['Verborgen dia']]), 'show="0"');

		return $this->zip($parts);
	}

	/**
	 * A mock File returning the given bytes, MIME type and name.
	 *
	 * @param string $content The bytes.
	 * @param string $mime The MIME type.
	 * @param string $name The file name.
	 *
	 * @return File&MockObject
	 */
	private function mockFile(string $content, string $mime = self::PPTX_MIME, string $name = 'les-3.pptx'): File {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($content);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(404);

		return $file;
	}

	/**
	 * Extract the lesson deck.
	 *
	 * @return array{slides: list<array<string, mixed>>, truncated: bool}
	 */
	private function extractLessonDeck(): array {
		$result = $this->extractor->extract(file: $this->mockFile(content: $this->lessonDeck()));
		$this->assertIsArray($result);

		return $result;
	}

	// ------------------------------------------------------------------
	// REQ-PPTX-001: presentation order
	// ------------------------------------------------------------------

	/**
	 * Slides follow the deck's slide list, not the file names.
	 *
	 * @return void
	 */
	public function testSlidesComeBackInDeckOrderNotFileOrder(): void {
		$slides = $this->extractLessonDeck()['slides'];

		$this->assertCount(3, $slides);
		$this->assertSame([1, 2, 3], array_column($slides, 'number'));
		$this->assertSame('Fotosynthese', $slides[0]['title'], 'slide2.xml is first in the deck');
		$this->assertSame('Water kookt', $slides[1]['title'], 'slide1.xml is second in the deck');

	}

	/**
	 * A hidden slide is returned, flagged, with its content.
	 *
	 * @return void
	 */
	public function testAHiddenSlideIsReturnedAndFlagged(): void {
		$slides = $this->extractLessonDeck()['slides'];

		$this->assertFalse($slides[0]['hidden']);
		$this->assertTrue($slides[2]['hidden']);
		$this->assertSame(['Verborgen dia'], $slides[2]['body']);

	}

	// ------------------------------------------------------------------
	// REQ-PPTX-002: title and body
	// ------------------------------------------------------------------

	/**
	 * Title and body are separated, runs are joined, empty paragraphs and the slide number are dropped.
	 *
	 * @return void
	 */
	public function testTitleAndBodyAreSeparatedAndRunsJoined(): void {
		$first = $this->extractLessonDeck()['slides'][0];

		$this->assertSame('Fotosynthese', $first['title']);
		$this->assertSame(['Planten maken voedsel', 'Licht, water en CO2'], $first['body']);

	}

	/**
	 * Grouped shapes and table cells keep their place; a compatibility block is read once.
	 *
	 * @return void
	 */
	public function testGroupsTablesAndCompatibilityBlocksKeepTheirPlace(): void {
		$second = $this->extractLessonDeck()['slides'][1];

		$this->assertSame(['Eerst', 'In de groep', 'Cel A', 'Cel B', 'Terugval'], $second['body']);

	}

	// ------------------------------------------------------------------
	// REQ-PPTX-003: notes
	// ------------------------------------------------------------------

	/**
	 * Notes come from the notes body only, not the slide image or number placeholders.
	 *
	 * @return void
	 */
	public function testNotesComeFromTheNotesBodyOnly(): void {
		$first = $this->extractLessonDeck()['slides'][0];

		$this->assertSame("Vraag eerst wat ze al weten.\nLaat ze daarna tekenen.", $first['notes']);

	}

	/**
	 * Notes written as a plain text box, as LibreOffice exports them, are read too.
	 *
	 * @return void
	 */
	public function testNotesWrittenAsAPlainTextBoxAreRead(): void {
		$parts = $this->presentation(['rId1']);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels(['rId1' => ['slide', 'slides/slide1.xml']]);
		$parts['ppt/slides/slide1.xml'] = $this->slide($this->textShape('title', [['Water kookt']]));
		$parts['ppt/slides/_rels/slide1.xml.rels'] = $this->rels(['rId3' => ['notesSlide', '../notesSlides/notesSlide1.xml']]);
		$parts['ppt/notesSlides/notesSlide1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:notes ' . self::NS . '>'
			. '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
			. $this->textShape('sldImg', [])
			. $this->textShape(null, [['Zet eerst de pan op het vuur.']])
			. '</p:spTree></p:cSld></p:notes>';

		$result = $this->extractor->extract(file: $this->mockFile(content: $this->zip($parts)));

		$this->assertSame('Zet eerst de pan op het vuur.', $result['slides'][0]['notes']);

	}

	/**
	 * A slide without a notes page has empty notes.
	 *
	 * @return void
	 */
	public function testASlideWithoutNotesHasEmptyNotes(): void {
		$this->assertSame('', $this->extractLessonDeck()['slides'][1]['notes']);

	}

	// ------------------------------------------------------------------
	// REQ-PPTX-004: images
	// ------------------------------------------------------------------

	/**
	 * Pictures come back in shape order: an embedded one by package path, a linked one by URL.
	 *
	 * @return void
	 */
	public function testImagesAreReferencedInShapeOrder(): void {
		$slides = $this->extractLessonDeck()['slides'];

		$this->assertSame(
			[
				['target' => 'ppt/media/image1.png', 'external' => false, 'name' => 'Blad', 'description' => 'Een blad in de zon'],
				['target' => 'https://example.org/zon.png', 'external' => true, 'name' => 'Zon', 'description' => ''],
			],
			$slides[0]['images']
		);
		$this->assertSame([], $slides[1]['images']);
		$this->assertStringNotContainsString('PNG-BYTES-NOT-READ', json_encode($slides, JSON_THROW_ON_ERROR), 'Image bytes are never returned.');

	}

	/**
	 * A target that climbs out of the package is kept as written and flagged external, never resolved.
	 *
	 * @return void
	 */
	public function testATargetClimbingOutOfThePackageIsFlaggedExternal(): void {
		$parts = $this->presentation(['rId1']);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels(['rId1' => ['slide', 'slides/slide1.xml']]);
		$parts['ppt/slides/slide1.xml'] = $this->slide($this->picture('Uit', '', 'r:embed="rId2"'));
		$parts['ppt/slides/_rels/slide1.xml.rels'] = $this->rels(['rId2' => ['image', '../../../../etc/passwd']]);

		$result = $this->extractor->extract(file: $this->mockFile(content: $this->zip($parts)));

		$this->assertSame(
			[['target' => '../../../../etc/passwd', 'external' => true, 'name' => 'Uit', 'description' => '']],
			$result['slides'][0]['images']
		);

	}

	// ------------------------------------------------------------------
	// REQ-PPTX-005: failure degrades to null
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
				$this->stringContains('[PresentationExtractor] Presentation extraction failed'),
				$this->callback(
					static function (array $context): bool {
						return str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'GEHEIM') === false
							&& $context['fileId'] === 404
							&& $context['mimeType'] === self::PPTX_MIME;
					}
				)
			);

		$result = $this->extractor->extract(file: $this->mockFile(content: 'GEHEIM-12345 this is not a zip package'));

		$this->assertNull($result);

	}

	/**
	 * A legacy binary deck is not read, and its bytes are never fetched.
	 *
	 * @return void
	 */
	public function testALegacyPptIsNotRead(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('application/vnd.ms-powerpoint');
		$file->method('getName')->willReturn('les-3.ppt');
		$file->method('getId')->willReturn(404);
		$file->expects($this->never())->method('getContent');

		$this->assertNull($this->extractor->extract(file: $file));

	}

	/**
	 * A zip without a presentation part returns null.
	 *
	 * @return void
	 */
	public function testAPackageWithoutAPresentationPartReturnsNull(): void {
		$bytes = $this->zip(['word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="urn:x"/>']);

		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: $bytes)));

	}

	/**
	 * A deck with an empty slide list returns null.
	 *
	 * @return void
	 */
	public function testADeckWithoutSlidesReturnsNull(): void {
		$parts = $this->presentation([]);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels([]);

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('holds no readable slides'),
				$this->callback(static fn (array $context): bool => $context['fileId'] === 404 && $context['mimeType'] === self::PPTX_MIME)
			);

		$this->assertNull($this->extractor->extract(file: $this->mockFile(content: $this->zip($parts))));

	}

	// ------------------------------------------------------------------
	// REQ-PPTX-006: bounds
	// ------------------------------------------------------------------

	/**
	 * A DOCTYPE in a slide part is refused, in UTF-8 and in UTF-16, and no entity is expanded.
	 *
	 * @param bool $utf16 Whether to store the slide part as UTF-16.
	 *
	 * @return void
	 */
	#[DataProvider('doctypeEncodings')]
	public function testADoctypeInASlidePartIsRefused(bool $utf16): void {
		$xml = '<?xml version="1.0" encoding="' . ($utf16 === true ? 'UTF-16' : 'UTF-8') . '"?>'
			. '<!DOCTYPE p:sld [<!ENTITY boom "ENTITEIT-UITGEVOUWEN">]>'
			. '<p:sld ' . self::NS . '><p:cSld><p:spTree>'
			. '<p:sp><p:nvSpPr><p:cNvPr id="2" name="x"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:txBody><a:p><a:r><a:t>&boom;</a:t></a:r></a:p></p:txBody></p:sp>'
			. '</p:spTree></p:cSld></p:sld>';
		if ($utf16 === true) {
			$xml = "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
		}

		$parts = $this->presentation(['rId1', 'rId2']);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels(['rId1' => ['slide', 'slides/slide1.xml'], 'rId2' => ['slide', 'slides/slide2.xml']]);
		$parts['ppt/slides/slide1.xml'] = $xml;
		$parts['ppt/slides/slide2.xml'] = $this->slide($this->textShape(null, [['Gewone dia']]));

		$this->logger->expects($this->atLeastOnce())
			->method('warning')
			->with(
				$this->stringContains('Refused parts'),
				$this->callback(static fn (array $context): bool => $context['parts'] === ['ppt/slides/slide1.xml'])
			);

		$result = $this->extractor->extract(file: $this->mockFile(content: $this->zip($parts)));

		$this->assertSame([], $result['slides'][0]['body'], 'The refused slide keeps its place with no content.');
		$this->assertSame(['Gewone dia'], $result['slides'][1]['body']);
		$this->assertStringNotContainsString('ENTITEIT-UITGEVOUWEN', json_encode($result, JSON_THROW_ON_ERROR));

	}

	/**
	 * A DOCTYPE in either encoding.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function doctypeEncodings(): array {
		return [
			'UTF-8, caught before parsing' => [false],
			'UTF-16, caught after parsing' => [true],
		];
	}

	/**
	 * A part larger than the cap is refused, whatever the zip directory claims.
	 *
	 * @return void
	 */
	public function testAPartLargerThanTheCapIsRefused(): void {
		$path = tempnam(sys_get_temp_dir(), 'pptx-test-');
		file_put_contents($path, $this->zip(['big.xml' => '<?xml version="1.0"?><r>' . str_repeat('a', 500) . '</r>', 'small.xml' => '<r/>']));
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::RDONLY);

		$package = new OoxmlPackage(zip: $zip, maxPartBytes: 100);

		$this->assertNull($package->readXml(path: 'big.xml'));
		$this->assertNotNull($package->readXml(path: 'small.xml'));
		$this->assertSame(['big.xml'], $package->refusedParts());

		$zip->close();
		unlink($path);

	}

	/**
	 * Past MAX_SLIDES the reading stops and the result says it was truncated.
	 *
	 * @return void
	 */
	public function testADeckPastTheSlideCapIsTruncated(): void {
		$count = (PresentationExtractor::MAX_SLIDES + 1);
		$ids = [];
		$relationships = [];
		for ($index = 1; $index <= $count; $index++) {
			$ids[] = 'rId' . $index;
			$relationships['rId' . $index] = ['slide', 'slides/slide1.xml'];
		}

		$parts = $this->presentation($ids);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels($relationships);
		$parts['ppt/slides/slide1.xml'] = $this->slide($this->textShape('title', [['Steeds dezelfde dia']]));

		$result = $this->extractor->extract(file: $this->mockFile(content: $this->zip($parts)));

		$this->assertTrue($result['truncated']);
		$this->assertCount(PresentationExtractor::MAX_SLIDES, $result['slides']);

	}

	/**
	 * A deck within the limits is not truncated.
	 *
	 * @return void
	 */
	public function testADeckWithinTheLimitsIsNotTruncated(): void {
		$this->assertFalse($this->extractLessonDeck()['truncated']);

	}

	/**
	 * Groups nested past the depth cap are not followed; shallow ones are.
	 *
	 * @return void
	 */
	public function testDeepGroupNestingIsBounded(): void {
		$depth = (PresentationSlideParser::MAX_GROUP_DEPTH + 5);
		$group = '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="9" name="g"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>';
		$shapes = $this->textShape(null, [['Ondiep']])
			. str_repeat($group, $depth) . $this->textShape(null, [['Te diep']]) . str_repeat('</p:grpSp>', $depth);

		$parts = $this->presentation(['rId1']);
		$parts['ppt/_rels/presentation.xml.rels'] = $this->rels(['rId1' => ['slide', 'slides/slide1.xml']]);
		$parts['ppt/slides/slide1.xml'] = $this->slide($shapes);

		$result = $this->extractor->extract(file: $this->mockFile(content: $this->zip($parts)));

		$this->assertSame(['Ondiep'], $result['slides'][0]['body']);

	}

	// ------------------------------------------------------------------
	// REQ-PPTX-007: supported formats
	// ------------------------------------------------------------------

	/**
	 * Support is decided by MIME type, or by extension when the MIME type is generic.
	 *
	 * @param string $mimeType The MIME type.
	 * @param string $fileName The file name.
	 * @param bool $expected Whether it is supported.
	 *
	 * @return void
	 */
	#[DataProvider('formats')]
	public function testSupportedFormats(string $mimeType, string $fileName, bool $expected): void {
		$this->assertSame($expected, $this->extractor->supports(mimeType: $mimeType, fileName: $fileName));

	}

	/**
	 * MIME type and file name pairs.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function formats(): array {
		return [
			'pptx' => [self::PPTX_MIME, 'anything.bin', true],
			'pptm' => ['application/vnd.ms-powerpoint.presentation.macroEnabled.12', 'les.pptm', true],
			'ppsx' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'les.ppsx', true],
			'generic MIME, pptx name' => ['application/octet-stream', 'les-3.PPTX', true],
			'zip MIME, pptx name' => ['application/zip', 'les-3.pptx', true],
			'legacy ppt' => ['application/vnd.ms-powerpoint', 'les-3.ppt', false],
			'odp' => ['application/vnd.oasis.opendocument.presentation', 'les-3.odp', false],
			'generic MIME, ppt name' => ['application/octet-stream', 'les-3.ppt', false],
			'docx MIME, pptx name' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'les-3.pptx', false],
		];
	}
}//end class
