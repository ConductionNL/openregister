<?php

/**
 * OpenRegister document body parser
 *
 * Turns the parsed body of a WordprocessingML document (ECMA-376 part 1,
 * section 17) into the structure a consumer needs to build a lesson or chapter
 * from it: the title, and sections that each start at a heading and hold the
 * paragraphs, lists, tables and pictures under it, in document order. Pure DOM
 * work with no I/O: paragraph and table content come from
 * DocumentContentReader, heading and list resolution from DocumentStyleMap, the
 * package access and its bounds from OoxmlPackage, the file handling from
 * DocumentExtractor (docx-structured-reader).
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
 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DOMDocument;
use DOMElement;

/**
 * Reads sections, paragraphs, lists, tables and picture references out of WordprocessingML body XML.
 *
 * @psalm-import-type DocumentImage from DocumentContentReader
 * @psalm-import-type Relationships from DocumentContentReader
 * @psalm-import-type ParagraphKind from DocumentStyleMap
 * @psalm-type DocumentListItem = array{text: string, level: int, ordered: bool}
 * @psalm-type DocumentParagraph = array{type: 'paragraph', text: string}
 * @psalm-type DocumentList = array{type: 'list', items: list<DocumentListItem>}
 * @psalm-type DocumentTable = array{type: 'table', rows: list<list<string>>}
 * @psalm-type DocumentBlock = DocumentParagraph|DocumentList|DocumentTable|DocumentImage
 * @psalm-type DocumentSection = array{heading: string, level: int, blocks: list<DocumentBlock>}
 *
 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */
class DocumentBodyParser {

	/**
	 * The most paragraphs and tables read from one document; the result then says `truncated: true`.
	 *
	 * @var int
	 */
	public const MAX_BLOCKS = 10000;

	/**
	 * Reads one paragraph's or table's content.
	 *
	 * @var DocumentContentReader
	 */
	private readonly DocumentContentReader $contentReader;

	/**
	 * Heading, title and list resolution for the document being parsed.
	 *
	 * @var DocumentStyleMap
	 */
	private DocumentStyleMap $styles;

	/**
	 * The body part's relationships, for picture targets.
	 *
	 * @var Relationships
	 */
	private array $relationships = [];

	/**
	 * The title found so far.
	 *
	 * @var string
	 */
	private string $title = '';

	/**
	 * Whether a title paragraph was seen, so a later one opens a section instead.
	 *
	 * @var bool
	 */
	private bool $titleTaken = false;

	/**
	 * Finished sections.
	 *
	 * @var list<DocumentSection>
	 */
	private array $sections = [];

	/**
	 * The section being filled.
	 *
	 * @var DocumentSection
	 */
	private array $current = ['heading' => '', 'level' => 0, 'blocks' => []];

	/**
	 * The numbering id of the list block at the end of the current section, or null.
	 *
	 * @var string|null
	 */
	private ?string $openListNumId = null;

	/**
	 * Paragraphs and tables read so far.
	 *
	 * @var int
	 */
	private int $blockCount = 0;

	/**
	 * Whether MAX_BLOCKS stopped the read.
	 *
	 * @var bool
	 */
	private bool $truncated = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->contentReader = new DocumentContentReader();
		$this->styles = new DocumentStyleMap(styles: null, numbering: null);
	}//end __construct()

	/**
	 * Parse a document part into its title and sections.
	 *
	 * @param DOMDocument $document The parsed document part.
	 * @param DOMDocument|null $styles The parsed styles part, or null.
	 * @param DOMDocument|null $numbering The parsed numbering part, or null.
	 * @param Relationships $relationships The document part's relationships.
	 *
	 * @return array{title: string, sections: list<DocumentSection>, truncated: bool}|null Null when the part has no body.
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-hostile-input-is-bounded-req-docx-009
	 */
	public function parse(DOMDocument $document, ?DOMDocument $styles, ?DOMDocument $numbering, array $relationships): ?array {
		$body = $document->getElementsByTagNameNS('*', 'body')->item(0);
		if (($body instanceof DOMElement) === false) {
			return null;
		}

		$this->styles = new DocumentStyleMap(styles: $styles, numbering: $numbering);
		$this->relationships = $relationships;
		$this->title = '';
		$this->titleTaken = false;
		$this->sections = [];
		$this->current = ['heading' => '', 'level' => 0, 'blocks' => []];
		$this->openListNumId = null;
		$this->blockCount = 0;
		$this->truncated = false;

		$this->walk(container: $body, depth: 0);
		$this->closeSection();

		return ['title' => $this->title, 'sections' => $this->sections, 'truncated' => $this->truncated];
	}//end parse()

	/**
	 * Walk the block-level children of a container: body, content control, custom XML or text box.
	 *
	 * @param DOMElement $container The container.
	 * @param int $depth How deeply this container is nested.
	 *
	 * @return void
	 */
	private function walk(DOMElement $container, int $depth): void {
		if ($depth > DocumentContentReader::MAX_DEPTH) {
			return;
		}

		foreach ($container->childNodes as $child) {
			if ($this->truncated === true) {
				return;
			}

			if ($child instanceof DOMElement) {
				$this->visitBlock(element: $child, depth: $depth);
			}
		}
	}//end walk()

	/**
	 * Take what one block-level element contributes.
	 *
	 * @param DOMElement $element The element.
	 * @param int $depth How deeply its container is nested.
	 *
	 * @return void
	 */
	private function visitBlock(DOMElement $element, int $depth): void {
		if ($element->localName === 'p') {
			$this->paragraph(paragraph: $element, depth: $depth);
			return;
		}

		if ($element->localName === 'tbl') {
			$this->table(table: $element, depth: $depth);
			return;
		}

		$content = $this->contentReader->wrapperContent(element: $element);
		if ($content !== null) {
			$this->walk(container: $content, depth: ($depth + 1));
		}
	}//end visitBlock()

	/**
	 * Place one paragraph: title, a new section, a list item or a paragraph block; then its pictures and text boxes.
	 *
	 * @param DOMElement $paragraph A `w:p` element.
	 * @param int $depth How deeply its container is nested.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-paragraph-text-is-read-once-with-runs-joined-req-docx-003
	 */
	private function paragraph(DOMElement $paragraph, int $depth): void {
		if ($this->countBlock() === false) {
			return;
		}

		$inline = $this->contentReader->paragraph(paragraph: $paragraph, relationships: $this->relationships);
		if ($inline['text'] !== '') {
			$this->placeText(kind: $this->styles->classify(paragraph: $paragraph), text: $inline['text']);
		}

		foreach ($inline['images'] as $image) {
			$this->appendBlock(block: $image);
		}

		foreach ($inline['textBoxes'] as $textBox) {
			$this->walk(container: $textBox, depth: ($depth + 1));
		}
	}//end paragraph()

	/**
	 * Put a paragraph's text where its kind says.
	 *
	 * @param ParagraphKind $kind The paragraph's kind.
	 * @param string $text The paragraph text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-the-document-carries-a-title-req-docx-002
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-lists-keep-their-items-levels-and-kind-req-docx-004
	 */
	private function placeText(array $kind, string $text): void {
		if ($kind['kind'] === 'title' && $this->titleTaken === false) {
			$this->title = $text;
			$this->titleTaken = true;
			return;
		}

		if ($kind['kind'] === 'title' || $kind['kind'] === 'heading') {
			$this->closeSection();
			$this->current = ['heading' => $text, 'level' => $kind['level'], 'blocks' => []];
			return;
		}

		if ($kind['kind'] === 'list') {
			$this->appendListItem(item: ['text' => $text, 'level' => $kind['level'], 'ordered' => $kind['ordered']], numId: $kind['numId']);
			return;
		}

		$this->appendBlock(block: ['type' => 'paragraph', 'text' => $text]);
	}//end placeText()

	/**
	 * Read one table as rows of cell text, then its pictures as image blocks.
	 *
	 * @param DOMElement $table A `w:tbl` element.
	 * @param int $depth How deeply its container is nested.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-tables-come-back-as-rows-of-cell-text-req-docx-005
	 */
	private function table(DOMElement $table, int $depth): void {
		if ($this->countBlock() === false) {
			return;
		}

		$images = [];
		$rows = $this->contentReader->tableRows(table: $table, relationships: $this->relationships, depth: ($depth + 1), images: $images);

		$this->appendBlock(block: ['type' => 'table', 'rows' => $rows]);
		foreach ($images as $image) {
			$this->appendBlock(block: $image);
		}
	}//end table()

	/**
	 * Add a list item: to the open list when it has the same numbering, else as a new list block.
	 *
	 * @param DocumentListItem $item The item.
	 * @param string $numId The numbering instance id.
	 *
	 * @return void
	 */
	private function appendListItem(array $item, string $numId): void {
		$last = (count($this->current['blocks']) - 1);
		if ($this->openListNumId === $numId && $last >= 0 && $this->current['blocks'][$last]['type'] === 'list') {
			$this->current['blocks'][$last]['items'][] = $item;
			return;
		}

		$this->current['blocks'][] = ['type' => 'list', 'items' => [$item]];
		$this->openListNumId = $numId;
	}//end appendListItem()

	/**
	 * Add a block to the current section; any block but a list item ends the open list.
	 *
	 * @param DocumentBlock $block The block.
	 *
	 * @return void
	 */
	private function appendBlock(array $block): void {
		$this->current['blocks'][] = $block;
		$this->openListNumId = null;
	}//end appendBlock()

	/**
	 * Finish the current section (kept when it has a heading or any block) and start an empty one.
	 *
	 * @return void
	 */
	private function closeSection(): void {
		if ($this->current['heading'] !== '' || $this->current['blocks'] !== []) {
			$this->sections[] = $this->current;
		}

		$this->current = ['heading' => '', 'level' => 0, 'blocks' => []];
		$this->openListNumId = null;
	}//end closeSection()

	/**
	 * Count one paragraph or table against MAX_BLOCKS.
	 *
	 * @return bool False once the cap is reached; the read then stops and says truncated.
	 */
	private function countBlock(): bool {
		if ($this->blockCount >= self::MAX_BLOCKS) {
			$this->truncated = true;
			return false;
		}

		$this->blockCount++;
		return true;
	}//end countBlock()
}//end class
