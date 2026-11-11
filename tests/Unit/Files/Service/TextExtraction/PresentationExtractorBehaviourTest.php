<?php

/**
 * A slide whose notes relationship points at a missing part.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction;

use OCA\OpenRegister\Files\Service\TextExtraction\PresentationExtractor;
use OCP\Files\File;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ZipArchive;

#[RequiresPhpExtension('zip')]
class PresentationExtractorBehaviourTest extends TestCase {

	private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
		. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
		. 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

	private const REL_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

	private function rels(array $relationships): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
		foreach ($relationships as $id => [$type, $target]) {
			$xml .= '<Relationship Id="' . $id . '" Type="' . self::REL_TYPE . $type . '" Target="' . $target . '"/>';
		}

		return $xml . '</Relationships>';
	}//end rels()

	private function spTree(string $text): string {
		return '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
			. '<p:sp><p:nvSpPr><p:cNvPr id="2" name="S"/><p:cNvSpPr/><p:nvPr><p:ph type="body"/></p:nvPr></p:nvSpPr><p:spPr/>'
			. '<p:txBody><a:bodyPr/><a:p><a:r><a:t>' . $text . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld>';
	}//end spTree()

	private function deck(bool $withNotesPart): string {
		$parts = [
			'[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'_rels/.rels' => $this->rels(['rId1' => ['officeDocument', 'ppt/presentation.xml']]),
			'ppt/presentation.xml' => '<?xml version="1.0" encoding="UTF-8"?><p:presentation ' . self::NS . '>'
				. '<p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst></p:presentation>',
			'ppt/_rels/presentation.xml.rels' => $this->rels(['rId1' => ['slide', 'slides/slide1.xml']]),
			'ppt/slides/slide1.xml' => '<?xml version="1.0" encoding="UTF-8"?><p:sld ' . self::NS . '>' . $this->spTree('Dia') . '</p:sld>',
			'ppt/slides/_rels/slide1.xml.rels' => $this->rels(['rId3' => ['notesSlide', '../notesSlides/notesSlide1.xml']]),
		];
		if ($withNotesPart === true) {
			$parts['ppt/notesSlides/notesSlide1.xml'] = '<?xml version="1.0" encoding="UTF-8"?><p:notes ' . self::NS . '>'
				. $this->spTree('Spreker') . '</p:notes>';
		}

		$path = tempnam(sys_get_temp_dir(), 'pptx-behaviour-');
		$zip = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}

		$zip->close();
		$bytes = (string)file_get_contents($path);
		unlink($path);

		return $bytes;
	}//end deck()

	private function extract(string $bytes): ?array {
		$file = $this->createMock(File::class);
		$file->method('getContent')->willReturn($bytes);
		$file->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.presentationml.presentation');
		$file->method('getName')->willReturn('deck.pptx');
		$file->method('getId')->willReturn(9);

		return (new PresentationExtractor(logger: $this->createMock(LoggerInterface::class)))->extract(file: $file);
	}//end extract()

	public function testNotesRelationshipToAMissingPartGivesEmptyNotes(): void {
		$result = $this->extract($this->deck(false));

		$this->assertSame('', $result['slides'][0]['notes']);
	}//end testNotesRelationshipToAMissingPartGivesEmptyNotes()

	public function testNotesRelationshipToAPresentPartIsRead(): void {
		$result = $this->extract($this->deck(true));

		$this->assertSame('Spreker', $result['slides'][0]['notes']);
	}//end testNotesRelationshipToAPresentPartIsRead()
}//end class
