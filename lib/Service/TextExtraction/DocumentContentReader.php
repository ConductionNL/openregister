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
 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DOMElement;

/**
 * Reads the text, picture references and text boxes of a paragraph, and the cell text of a table.
 *
 * @psalm-type DocumentImage = array{type: 'image', target: string, external: bool, name: string, description: string}
 * @psalm-type InlineContent = array{text: string, images: list<DocumentImage>, textBoxes: list<DOMElement>}
 * @psalm-type PictureContext = array{name: string, description: string}
 * @psalm-type Relationships = array<string, array{type: string, target: string, external: bool}>
 *
 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
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
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-image-references-come-back-in-document-order-req-docx-006
	 */
	public function paragraph(DOMElement $paragraph, array $relationships): array {
		$inline = ['text' => '', 'images' => [], 'textBoxes' => []];
		$this->collect(element: $paragraph, relationships: $relationships, inline: $inline, picture: ['name' => '', 'description' => '']);
		$inline['text'] = trim((string)preg_replace('/\s+/u', ' ', $inline['text']));

		return $inline;
	}//end paragraph()

	/**
	 * The rows of a table, each a list of cell texts, as written; a cell's lines are joined by a newline.
	 *
	 * @param DOMElement $table A `w:tbl` element.
	 * @param Relationships $relationships The document part's relationships.
	 * @param int $depth How deeply the table is nested.
	 * @param list<DocumentImage> $images Pictures found in the cells, extended in place.
	 *
	 * @return list<list<string>>
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-tables-come-back-as-rows-of-cell-text-req-docx-005
	 */
	public function tableRows(DOMElement $table, array $relationships, int $depth, array &$images): array {
		$rows = [];
		foreach ($this->elements->children(parent: $table, localName: 'tr') as $row) {
			$cells = [];
			foreach ($this->elements->children(parent: $row, localName: 'tc') as $cell) {
				$lines = $this->containerLines(container: $cell, relationships: $relationships, depth: $depth, images: $images);
				$cells[] = implode("\n", $lines);
			}

			$rows[] = $cells;
		}

		return $rows;
	}//end tableRows()

	/**
	 * The block container a wrapper element holds: a content control's content, or custom XML itself.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return DOMElement|null Null for anything that is not a block wrapper.
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-hostile-input-is-bounded-req-docx-009
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
	 * @param DOMElement $container The cell, text box, or wrapper content.
	 * @param Relationships $relationships The document part's relationships.
	 * @param int $depth How deeply the container is nested.
	 * @param list<DocumentImage> $images Pictures found, extended in place.
	 *
	 * @return list<string>
	 */
	private function containerLines(DOMElement $container, array $relationships, int $depth, array &$images): array {
		if ($depth > self::MAX_DEPTH) {
			return [];
		}

		$lines = [];
		foreach ($container->childNodes as $child) {
			if ($child instanceof DOMElement) {
				array_push($lines, ...$this->elementLines(element: $child, relationships: $relationships, depth: $depth, images: $images));
			}
		}

		return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
	}//end containerLines()

	/**
	 * The text lines one element inside a cell contributes.
	 *
	 * @param DOMElement $element A paragraph, nested table, content control or custom XML wrapper.
	 * @param Relationships $relationships The document part's relationships.
	 * @param int $depth How deeply its container is nested.
	 * @param list<DocumentImage> $images Pictures found, extended in place.
	 *
	 * @return list<string>
	 */
	private function elementLines(DOMElement $element, array $relationships, int $depth, array &$images): array {
		if ($element->localName === 'p') {
			$inline = $this->paragraph(paragraph: $element, relationships: $relationships);
			array_push($images, ...$inline['images']);
			$lines = [$inline['text']];
			foreach ($inline['textBoxes'] as $textBox) {
				array_push($lines, ...$this->containerLines(container: $textBox, relationships: $relationships, depth: ($depth + 1), images: $images));
			}

			return $lines;
		}

		if ($element->localName === 'tbl') {
			return array_merge(...$this->tableRows(table: $element, relationships: $relationships, depth: ($depth + 1), images: $images));
		}

		$content = $this->wrapperContent(element: $element);
		if ($content === null) {
			return [];
		}

		return $this->containerLines(container: $content, relationships: $relationships, depth: ($depth + 1), images: $images);
	}//end elementLines()

	/**
	 * Collect text, pictures and text boxes under an element, never entering a fallback or a text box.
	 *
	 * @param DOMElement $element The element whose children to read.
	 * @param Relationships $relationships The document part's relationships.
	 * @param InlineContent $inline The content so far, extended in place.
	 * @param PictureContext $picture The name and alt text of the drawing or shape being read.
	 *
	 * @return void
	 */
	private function collect(DOMElement $element, array $relationships, array &$inline, array $picture): void {
		foreach ($element->childNodes as $child) {
			if (($child instanceof DOMElement) === false || in_array($child->localName, self::SKIPPED, true) === true) {
				continue;
			}

			if ($child->localName === 'blip' || $child->localName === 'imagedata') {
				$inline['images'][] = $this->image(element: $child, relationships: $relationships, picture: $picture);
				continue;
			}

			if ($this->collectText(element: $child, inline: $inline) === true) {
				continue;
			}

			$context = $this->pictureContext(element: $child, picture: $picture);
			$this->collect(element: $child, relationships: $relationships, inline: $inline, picture: $context);
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
	 * @param DOMElement $element An `a:blip` (drawing) or `v:imagedata` (VML) element.
	 * @param Relationships $relationships The document part's relationships.
	 * @param PictureContext $picture The name and alt text of the enclosing drawing or shape.
	 *
	 * @return DocumentImage
	 */
	private function image(DOMElement $element, array $relationships, array $picture): array {
		// A drawing names its part in r:embed or its URL in r:link; VML uses r:id and names the picture in o:title.
		$relationshipId = $this->elements->relationshipAttribute(element: $element, localNames: ['embed', 'link', 'id']);
		$relationship = ($relationships[$relationshipId] ?? ['target' => '', 'external' => false]);

		$name = $picture['name'];
		if ($name === '' && $element->localName === 'imagedata') {
			$name = $this->elements->attribute(element: $element, localName: 'title');
		}

		return [
			'type' => 'image',
			'target' => $relationship['target'],
			'external' => $relationship['external'],
			'name' => $name,
			'description' => $picture['description'],
		];
	}//end image()
}//end class
