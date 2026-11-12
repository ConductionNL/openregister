<?php

/**
 * OpenRegister document content reader
 *
 * Reads the content of one WordprocessingML paragraph or table (ECMA-376 part
 * 1, sections 17.3 and 17.4). A paragraph is read in a single pass: its text
 * with runs joined and whitespace collapsed, its picture references with their
 * alt text, and its text boxes. The pass never enters a markup-compatibility
 * fallback (`mc:Fallback`), so a shape stored twice for older readers is read
 * once, and it never enters a text box (`w:txbxContent`): text boxes are handed
 * back so the caller can walk them as blocks of their own. Deleted and
 * moved-away text of tracked changes and field instructions are not read. A
 * table is read as rows of cell text, nested tables and content controls
 * folded into their cell. Pure DOM work with no I/O (docx-structured-reader).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DOMElement;

/**
 * Reads the text, picture references and text boxes of a paragraph, and the cell text of a table.
 *
 * @psalm-type DocumentImage = array{type: 'image', target: string, external: bool, name: string, description: string}
 * @psalm-type InlineContent = array{text: string, images: list<DocumentImage>, textBoxes: list<DOMElement>}
 * @psalm-type PictureContext = array{name: string, description: string}
 * @psalm-type Relationships = array<string, array{type: string, target: string, external: bool}>
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
 */
class DocumentContentReader {

	/**
	 * How deep content controls, nested tables and text boxes are followed.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 20;

	/**
	 * Inline elements whose content is never paragraph text: properties, deletions,
	 * moved-away text, field instructions, and the compatibility copy of a shape.
	 *
	 * @var list<string>
	 */
	private const SKIPPED = ['pPr', 'rPr', 'del', 'moveFrom', 'delText', 'instrText', 'Fallback'];

	/**
	 * Inline elements read as a space.
	 *
	 * @var list<string>
	 */
	private const SPACES = ['tab', 'ptab', 'br', 'cr'];

	/**
	 * Local-name lookups.
	 *
	 * @var OoxmlElements
	 */
	private readonly OoxmlElements $elements;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->elements = new OoxmlElements();
	}//end __construct()

	/**
	 * Read a paragraph's text, pictures and text boxes.
	 *
	 * @param DOMElement $paragraph A `w:p` element.
	 * @param Relationships $relationships The document part's relationships.
	 *
	 * @return InlineContent The text with runs joined and whitespace collapsed; pictures and text boxes in order.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-image-references-come-back-in-document-order-req-docx-006
	 */
	public function paragraph(DOMElement $paragraph, array $relationships): array {
		$run = new InlineReadRun(relationships: $relationships);
		$this->collect(run: $run, element: $paragraph);
		$inline = $run->inline;
		$inline['text'] = trim((string)preg_replace('/\s+/u', ' ', $inline['text']));

		return $inline;
	}//end paragraph()

	/**
	 * A table's rows, each a list of cell texts, as written (a cell's lines are joined by a newline),
	 * and the pictures found in its cells.
	 *
	 * @param DOMElement $table A `w:tbl` element.
	 * @param Relationships $relationships The document part's relationships.
	 * @param int $depth How deeply the table is nested.
	 *
	 * @return TableContent The rows and the pictures.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-tables-come-back-as-rows-of-cell-text-req-docx-005
	 */
	public function table(DOMElement $table, array $relationships, int $depth): TableContent {
		$run = new BlockReadRun(relationships: $relationships, depth: $depth);
		$rows = $this->rowsOf(run: $run, table: $table);

		return new TableContent(rows: $rows, images: $run->images);
	}//end table()

	/**
	 * The text of each cell of a table, row by row, at the run's depth.
	 *
	 * @param BlockReadRun $run   The read: relationships, depth and pictures so far.
	 * @param DOMElement   $table A `w:tbl` element.
	 *
	 * @return list<list<string>>
	 */
	private function rowsOf(BlockReadRun $run, DOMElement $table): array {
		$rows = [];
		foreach ($this->elements->children(parent: $table, localName: 'tr') as $row) {
			$cells = [];
			foreach ($this->elements->children(parent: $row, localName: 'tc') as $cell) {
				$cells[] = implode("\n", $this->containerLines(run: $run, container: $cell));
			}

			$rows[] = $cells;
		}

		return $rows;
	}//end rowsOf()

	/**
	 * The block container a wrapper element holds: a content control's content, or custom XML itself.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return DOMElement|null Null for anything that is not a block wrapper.
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-hostile-input-is-bounded-req-docx-009
	 */
	public function wrapperContent(DOMElement $element): ?DOMElement {
		if ($element->localName === 'sdt') {
			return $this->elements->child(parent: $element, localName: 'sdtContent');
		}

		if ($element->localName === 'customXml') {
			return $element;
		}

		return null;
	}//end wrapperContent()

	/**
	 * The non-empty text lines of a cell or text box, nested tables and content controls included.
	 *
	 * @param BlockReadRun $run       The read: relationships, the container's depth and pictures so far.
	 * @param DOMElement   $container The cell, text box, or wrapper content.
	 *
	 * @return list<string>
	 */
	private function containerLines(BlockReadRun $run, DOMElement $container): array {
		if ($run->depth > self::MAX_DEPTH) {
			return [];
		}

		$lines = [];
		foreach ($container->childNodes as $child) {
			if ($child instanceof DOMElement) {
				array_push($lines, ...$this->elementLines(run: $run, element: $child));
			}
		}

		return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
	}//end containerLines()

	/**
	 * The text lines one element inside a cell contributes.
	 *
	 * @param BlockReadRun $run     The read: relationships, its container's depth and pictures so far.
	 * @param DOMElement   $element A paragraph, nested table, content control or custom XML wrapper.
	 *
	 * @return list<string>
	 */
	private function elementLines(BlockReadRun $run, DOMElement $element): array {
		if ($element->localName === 'p') {
			$inline = $this->paragraph(paragraph: $element, relationships: $run->relationships);
			array_push($run->images, ...$inline['images']);
			$lines = [$inline['text']];
			foreach ($inline['textBoxes'] as $textBox) {
				array_push($lines, ...$this->nestedLines(run: $run, container: $textBox));
			}

			return $lines;
		}

		if ($element->localName === 'tbl') {
			$run->depth++;
			$rows = $this->rowsOf(run: $run, table: $element);
			$run->depth--;
			return array_merge(...$rows);
		}

		$content = $this->wrapperContent(element: $element);
		if ($content === null) {
			return [];
		}

		return $this->nestedLines(run: $run, container: $content);
	}//end elementLines()

	/**
	 * The lines of a container one level deeper than the run's depth.
	 *
	 * @param BlockReadRun $run       The read: relationships, depth and pictures so far.
	 * @param DOMElement   $container A text box or wrapper content.
	 *
	 * @return list<string>
	 */
	private function nestedLines(BlockReadRun $run, DOMElement $container): array {
		$run->depth++;
		$lines = $this->containerLines(run: $run, container: $container);
		$run->depth--;

		return $lines;
	}//end nestedLines()

	/**
	 * Collect text, pictures and text boxes under an element, never entering a fallback or a text box.
	 *
	 * @param InlineReadRun $run     The read: relationships, content so far and the picture being read.
	 * @param DOMElement    $element The element whose children to read.
	 *
	 * @return void
	 */
	private function collect(InlineReadRun $run, DOMElement $element): void {
		$picture = $run->picture;
		foreach ($element->childNodes as $child) {
			if (($child instanceof DOMElement) === false || in_array($child->localName, self::SKIPPED, true) === true) {
				continue;
			}

			if ($child->localName === 'blip' || $child->localName === 'imagedata') {
				$run->inline['images'][] = $this->image(run: $run, element: $child);
				continue;
			}

			if ($this->collectText(element: $child, inline: $run->inline) === true) {
				continue;
			}

			$run->picture = $this->pictureContext(element: $child, picture: $picture);
			$this->collect(run: $run, element: $child);
			$run->picture = $picture;
		}
	}//end collect()

	/**
	 * Take an element that is text, a space, a hyphen, or a text box.
	 *
	 * @param DOMElement $element The element.
	 * @param InlineContent $inline The content so far, extended in place.
	 *
	 * @return bool True when the element was taken and must not be descended into.
	 */
	private function collectText(DOMElement $element, array &$inline): bool {
		$name = $element->localName;
		if ($name === 't') {
			$inline['text'] .= $element->textContent;
			return true;
		}

		if (in_array($name, self::SPACES, true) === true) {
			$inline['text'] .= ' ';
			return true;
		}

		if ($name === 'noBreakHyphen') {
			$inline['text'] .= '-';
			return true;
		}

		if ($name === 'txbxContent') {
			$inline['textBoxes'][] = $element;
			return true;
		}

		return false;
	}//end collectText()

	/**
	 * The name and alt text in force below an element: a drawing's `wp:docPr`, then a picture's `cNvPr`, or a VML shape's `alt`.
	 *
	 * @param DOMElement $element The element about to be descended into.
	 * @param PictureContext $picture The context above it.
	 *
	 * @return PictureContext
	 */
	private function pictureContext(DOMElement $element, array $picture): array {
		if ($element->localName === 'drawing') {
			// A new drawing starts a fresh context: its own wp:docPr names it.
			$properties = $this->elements->firstDescendant(root: $element, localName: 'docPr');
			return $this->fillPicture(picture: ['name' => '', 'description' => ''], properties: $properties);
		}

		if ($element->localName === 'pic') {
			return $this->fillPicture(picture: $picture, properties: $this->elements->firstDescendant(root: $element, localName: 'cNvPr'));
		}

		if ($element->localName === 'shape' && $picture['description'] === '') {
			$picture['description'] = $this->elements->attribute(element: $element, localName: 'alt');
		}

		return $picture;
	}//end pictureContext()

	/**
	 * Fill an empty name or alt text from a properties element (`wp:docPr` or `cNvPr`).
	 *
	 * @param PictureContext $picture The context so far.
	 * @param DOMElement|null $properties The properties element, or null.
	 *
	 * @return PictureContext
	 */
	private function fillPicture(array $picture, ?DOMElement $properties): array {
		if ($picture['name'] === '') {
			$picture['name'] = $this->elements->attribute(element: $properties, localName: 'name');
		}

		if ($picture['description'] === '') {
			$picture['description'] = $this->elements->attribute(element: $properties, localName: 'descr');
		}

		return $picture;
	}//end fillPicture()

	/**
	 * One image block: the target from the relationships, whether it is linked, its name and alt text.
	 *
	 * @param InlineReadRun $run     The read: relationships and the enclosing drawing or shape.
	 * @param DOMElement    $element An `a:blip` (drawing) or `v:imagedata` (VML) element.
	 *
	 * @return DocumentImage
	 */
	private function image(InlineReadRun $run, DOMElement $element): array {
		// A drawing names its part in r:embed or its URL in r:link; VML uses r:id and names the picture in o:title.
		$relationshipId = $this->elements->relationshipAttribute(element: $element, localNames: ['embed', 'link', 'id']);
		$relationship = ($run->relationships[$relationshipId] ?? ['target' => '', 'external' => false]);

		$name = $run->picture['name'];
		if ($name === '' && $element->localName === 'imagedata') {
			$name = $this->elements->attribute(element: $element, localName: 'title');
		}

		return [
			'type' => 'image',
			'target' => $relationship['target'],
			'external' => $relationship['external'],
			'name' => $name,
			'description' => $run->picture['description'],
		];
	}//end image()
}//end class
