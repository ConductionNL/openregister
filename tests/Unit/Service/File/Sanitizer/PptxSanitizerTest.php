<?php

/**
 * PptxSanitizerTest
 *
 * Unit tests for {@see \OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer}.
 * A presentation is built in-test with a shown slide, a hidden slide with
 * notes, and a third slide with speaker notes and a comment; the sanitised
 * package is read back part by part.
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

use OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Speaker notes, hidden slides and comments do not go out.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\PptxSanitizer
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlPackage
 * @covers \OCA\OpenRegister\Files\Service\File\Sanitizer\OoxmlCommonParts
 * @covers \OCA\OpenRegister\Files\Service\File\SanitizationHiddenCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\Sanitizer\OfficePartSurgery
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationReport
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts
 * @uses   \OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts
 */
class PptxSanitizerTest extends TestCase {

	private const MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

	private const NS = 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

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
	 * Sanitise the fixture presentation and read the result back.
	 *
	 * @return void
	 */
	private function sanitiseFixture(): void {
		$source = tempnam(sys_get_temp_dir(), 'pptx_') . '.pptx';
		$dest = tempnam(sys_get_temp_dir(), 'pptx_out_') . '.pptx';
		array_push($this->paths, $source, $dest);
		$zip = new ZipArchive();
		$zip->open($source, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($this->fixture() as $name => $bytes) {
			$zip->addFromString($name, $bytes);
		}

		$zip->close();

		$this->report = (new PptxSanitizer(sentinel: 'Geanonimiseerd'))->sanitize($source, $dest)->jsonSerialize();

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($dest), 'the output is not a zip package');
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$this->out[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
		}

		$zip->close();
	}//end sanitiseFixture()

	public function testItSupportsPptxOnly(): void {
		$sanitizer = new PptxSanitizer();

		$this->assertTrue($sanitizer->supports(self::MIME));
		$this->assertFalse($sanitizer->supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
	}

	public function testSpeakerNotesAreRemovedAndReported(): void {
		$this->sanitiseFixture();

		$this->assertSame([], array_values(array_filter(array_keys($this->out), static fn (string $name): bool => str_starts_with($name, 'ppt/notesSlides/'))));
		$this->assertStringNotContainsString('notesSlide', $this->out['ppt/slides/_rels/slide3.xml.rels']);
		$this->assertStringNotContainsString('notesSlide', $this->out['[Content_Types].xml']);
		$this->assertStringNotContainsString('Vergeet Jan niet te noemen', implode("\n", $this->out));
		$this->assertArrayHasKey('ppt/slides/slide3.xml', $this->out, 'the slide went with its notes');
		$this->assertSame(1, $this->report['speakerNotesRemoved'], 'notes of a removed hidden slide count as the slide, not as notes');
	}

	public function testAHiddenSlideIsRemoved(): void {
		$this->sanitiseFixture();

		$this->assertArrayNotHasKey('ppt/slides/slide2.xml', $this->out);
		$this->assertArrayNotHasKey('ppt/slides/_rels/slide2.xml.rels', $this->out);
		$this->assertStringNotContainsString('Verborgen begroting', implode("\n", $this->out));
		$this->assertSame(2, substr_count($this->out['ppt/presentation.xml'], '<p:sldId '));
		$this->assertStringNotContainsString('slide2.xml', $this->out['ppt/_rels/presentation.xml.rels']);
		$this->assertStringNotContainsString('slide2.xml', $this->out['[Content_Types].xml']);
		$this->assertArrayHasKey('ppt/slideLayouts/slideLayout1.xml', $this->out, 'a layout the shown slides use was removed');
		$this->assertSame(1, $this->report['hiddenSlidesRemoved']);
	}

	public function testCommentsAndTheirAuthorsAreRemoved(): void {
		$this->sanitiseFixture();

		$this->assertArrayNotHasKey('ppt/comments/comment1.xml', $this->out);
		$this->assertArrayNotHasKey('ppt/commentAuthors.xml', $this->out);
		$this->assertStringNotContainsString('Piet Pietersen', implode("\n", $this->out));
		$this->assertStringContainsString('<dc:creator>Geanonimiseerd</dc:creator>', $this->out['docProps/core.xml']);
		$this->assertSame(1, $this->report['commentsRemoved']);
		$this->assertSame(0, $this->report['hiddenSheetsRemoved']);
	}

	/**
	 * The fixture presentation.
	 *
	 * @return array<string, string>
	 */
	private function fixture(): array {
		$rel = static fn (string $id, string $type, string $target): string => '<Relationship Id="' . $id . '" Type="' . $type . '" Target="' . $target . '"/>';
		$rels = static fn (string ...$items): string => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $items) . '</Relationships>';
		$override = static fn (string $part): string => '<Override PartName="/' . $part . '" ContentType="application/xml"/>';
		$slide = static fn (string $text, string $show = ''): string => '<?xml version="1.0"?><p:sld ' . self::NS . $show . '><p:cSld><p:spTree><p:sp><p:txBody><a:p xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:r><a:t>' . $text . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
		$layout = $rel('rId1', self::RELS . '/slideLayout', '../slideLayouts/slideLayout1.xml');

		return [
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/>'
				. $override('ppt/presentation.xml') . $override('ppt/slides/slide1.xml') . $override('ppt/slides/slide2.xml') . $override('ppt/slides/slide3.xml')
				. $override('ppt/notesSlides/notesSlide1.xml') . $override('ppt/notesSlides/notesSlide2.xml') . $override('ppt/comments/comment1.xml') . '</Types>',
			'_rels/.rels' => $rels($rel('rId1', self::RELS . '/officeDocument', 'ppt/presentation.xml'), $rel('rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml')),
			'docProps/core.xml' => '<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Piet Pietersen</dc:creator></cp:coreProperties>',
			'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation ' . self::NS . '><p:sldIdLst><p:sldId id="256" r:id="rId2"/><p:sldId id="257" r:id="rId3"/><p:sldId id="258" r:id="rId4"/></p:sldIdLst></p:presentation>',
			'ppt/_rels/presentation.xml.rels' => $rels(
				$rel('rId2', self::RELS . '/slide', 'slides/slide1.xml'),
				$rel('rId3', self::RELS . '/slide', 'slides/slide2.xml'),
				$rel('rId4', self::RELS . '/slide', 'slides/slide3.xml'),
				$rel('rId5', self::RELS . '/commentAuthors', 'commentAuthors.xml')
			),
			'ppt/slides/slide1.xml' => $slide('Welkom'),
			'ppt/slides/_rels/slide1.xml.rels' => $rels($layout),
			'ppt/slides/slide2.xml' => $slide('Verborgen begroting', ' show="0"'),
			'ppt/slides/_rels/slide2.xml.rels' => $rels($layout, $rel('rId2', self::RELS . '/notesSlide', '../notesSlides/notesSlide2.xml')),
			'ppt/slides/slide3.xml' => $slide('Resultaten'),
			'ppt/slides/_rels/slide3.xml.rels' => $rels(
				$layout,
				$rel('rId2', self::RELS . '/notesSlide', '../notesSlides/notesSlide1.xml'),
				$rel('rId3', self::RELS . '/comments', '../comments/comment1.xml')
			),
			'ppt/slideLayouts/slideLayout1.xml' => '<?xml version="1.0"?><p:sldLayout ' . self::NS . '/>',
			'ppt/notesSlides/notesSlide1.xml' => '<?xml version="1.0"?><p:notes ' . self::NS . '><p:cSld>Vergeet Jan niet te noemen</p:cSld></p:notes>',
			'ppt/notesSlides/_rels/notesSlide1.xml.rels' => $rels($rel('rId1', self::RELS . '/slide', '../slides/slide3.xml')),
			'ppt/notesSlides/notesSlide2.xml' => '<?xml version="1.0"?><p:notes ' . self::NS . '><p:cSld>Verborgen begroting toelichten</p:cSld></p:notes>',
			'ppt/notesSlides/_rels/notesSlide2.xml.rels' => $rels($rel('rId1', self::RELS . '/slide', '../slides/slide2.xml')),
			'ppt/comments/comment1.xml' => '<?xml version="1.0"?><p:cmLst ' . self::NS . '><p:cm authorId="0"><p:text>Klopt dit?</p:text></p:cm></p:cmLst>',
			'ppt/commentAuthors.xml' => '<?xml version="1.0"?><p:cmAuthorLst ' . self::NS . '><p:cmAuthor id="0" name="Piet Pietersen"/></p:cmAuthorLst>',
		];
	}//end fixture()
}//end class
