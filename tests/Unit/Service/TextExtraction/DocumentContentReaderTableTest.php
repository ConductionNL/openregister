<?php

/**
 * DocumentContentReaderTableTest
 *
 * Characterisation of DocumentContentReader::table(): cell text, text
 * boxes, wrappers, pictures collected across cells, and the depth bound.
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

namespace Unit\Service\TextExtraction;

use DOMDocument;
use DOMElement;
use OCA\OpenRegister\Files\Service\TextExtraction\DocumentContentReader;
use PHPUnit\Framework\TestCase;

/**
 * Tables read by {@see DocumentContentReader::table()}.
 */
class DocumentContentReaderTableTest extends TestCase {

	private const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
		. ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
		. ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
		. ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
		. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
		. ' xmlns:v="urn:schemas-microsoft-com:vml"';

	/**
	 * Parse a w:tbl element.
	 *
	 * @param string $xml The table markup.
	 *
	 * @return DOMElement
	 */
	private function table(string $xml): DOMElement {
		$doc = new DOMDocument();
		$doc->loadXML('<w:document ' . self::NS . '><w:body>' . $xml . '</w:body></w:document>');
		return $doc->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'tbl')->item(0);
	}//end table()

	/**
	 * A paragraph with one text run.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private function p(string $text): string {
		return '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p>';
	}//end p()

	/**
	 * A paragraph holding one drawing.
	 *
	 * @param string $rid Relationship id.
	 *
	 * @return string
	 */
	private function picture(string $rid): string {
		return '<w:p><w:r><w:drawing><wp:inline><wp:docPr name="Foto ' . $rid . '" descr="Alt ' . $rid . '"/>'
			. '<a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="' . $rid . '"/></pic:blipFill></pic:pic>'
			. '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
	}//end picture()

	/**
	 * Cells read text, text boxes, wrappers and nested tables; pictures are appended in document order.
	 *
	 * @return void
	 */
	public function testCellsAndPictures(): void {
		$textBox = '<w:p><w:r><w:t>Voor</w:t></w:r><w:r><w:pict><v:shape><v:textbox><w:txbxContent>'
			. $this->p('In kader') . '</w:txbxContent></v:textbox></v:shape></w:pict></w:r></w:p>';
		$table = $this->table(
			'<w:tbl><w:tr><w:tc>' . $this->p('A') . $this->picture('rId1') . '</w:tc><w:tc>' . $textBox . '</w:tc></w:tr>'
			. '<w:tr><w:tc><w:sdt><w:sdtContent>' . $this->p('Veld') . $this->picture('rId2') . '</w:sdtContent></w:sdt></w:tc>'
			. '<w:tc><w:tbl><w:tr><w:tc>' . $this->p('Binnen') . '</w:tc></w:tr></w:tbl></w:tc></w:tr></w:tbl>'
		);
		$relationships = [
			'rId1' => ['target' => 'word/media/1.png', 'external' => false],
			'rId2' => ['target' => 'https://example.org/2.png', 'external' => true],
		];

		$read = (new DocumentContentReader())->table(table: $table, relationships: $relationships, depth: 1);

		self::assertSame([['A', "Voor\nIn kader"], ['Veld', 'Binnen']], $read->rows);
		self::assertSame(
			[
				['type' => 'image', 'target' => 'word/media/1.png', 'external' => false, 'name' => 'Foto rId1', 'description' => 'Alt rId1'],
				['type' => 'image', 'target' => 'https://example.org/2.png', 'external' => true, 'name' => 'Foto rId2', 'description' => 'Alt rId2'],
			],
			$read->images
		);
	}//end testCellsAndPictures()

	/**
	 * Wrappers nested inside a cell stop at MAX_DEPTH; the table's own depth counts.
	 *
	 * @return void
	 */
	public function testDepthBoundInsideACell(): void {
		$cell = '';
		for ($level = 1; $level <= 6; $level++) {
			$cell .= '<w:sdt><w:sdtContent>' . $this->p('Niveau ' . $level);
		}

		$cell .= str_repeat('</w:sdtContent></w:sdt>', 6);
		$table = $this->table('<w:tbl><w:tr><w:tc>' . $cell . '</w:tc></w:tr></w:tbl>');
		$start = (DocumentContentReader::MAX_DEPTH - 3);
		$read = (new DocumentContentReader())->table(table: $table, relationships: [], depth: $start);

		self::assertSame([["Niveau 1\nNiveau 2\nNiveau 3"]], $read->rows);
		$tooDeep = (new DocumentContentReader())->table(table: $table, relationships: [], depth: (DocumentContentReader::MAX_DEPTH + 1));
		self::assertSame([['']], $tooDeep->rows);
	}//end testDepthBoundInsideACell()
}//end class
