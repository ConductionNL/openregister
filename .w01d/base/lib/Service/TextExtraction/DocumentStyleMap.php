<?php

/**
 * OpenRegister document style map
 *
 * Answers the two questions a WordprocessingML paragraph cannot answer on its
 * own (ECMA-376 part 1, sections 17.7 and 17.9): is it the title, a heading
 * (and at which level) or a list item, and is that list numbered or bulleted.
 * The answers come from the paragraph's own properties first, then from its
 * paragraph style in the styles part, following the style's `basedOn` chain,
 * and for lists from the numbering part. Pure DOM work with no I/O: the
 * package access and its bounds live in OoxmlPackage, the file handling in
 * DocumentExtractor (docx-structured-reader).
 *
 * Styles are matched by name as well as by id, because Word localises the id
 * (`Kop1` in Dutch Word) but keeps the name (`heading 1`). Elements and
 * attributes are matched by local name, so a transitional and a strict OOXML
 * package read the same way.
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
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DOMDocument;
use DOMElement;

/**
 * Resolves heading levels, the title and list numbering for WordprocessingML paragraphs.
 *
 * @psalm-type ParagraphKind = array{kind: 'title'|'heading'|'list'|'text', level: int, numId: string, ordered: bool}
 * @psalm-type StyleEntry = array{name: string, basedOn: string, outline: int|null, numId: string, ilvl: int|null}
 *
 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */
class DocumentStyleMap {

	/**
	 * How many `basedOn` steps are followed before a style chain is given up.
	 *
	 * @var int
	 */
	public const MAX_STYLE_CHAIN = 20;

	/**
	 * Number formats that mark a bulleted (not numbered) list level.
	 *
	 * @var list<string>
	 */
	private const UNORDERED_FORMATS = ['', 'bullet', 'none'];

	/**
	 * Local-name lookups.
	 *
	 * @var OoxmlElements
	 */
	private readonly OoxmlElements $elements;

	/**
	 * Paragraph styles by style id.
	 *
	 * @var array<string, StyleEntry>
	 */
	private array $styles = [];

	/**
	 * The abstract numbering id behind each numbering instance id.
	 *
	 * @var array<string, string>
	 */
	private array $abstractIds = [];

	/**
	 * The number format of each level of each abstract numbering definition.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $formats = [];

	/**
	 * Constructor.
	 *
	 * @param DOMDocument|null $styles The parsed styles part, or null when the package has none.
	 * @param DOMDocument|null $numbering The parsed numbering part, or null when the package has none.
	 */
	public function __construct(?DOMDocument $styles, ?DOMDocument $numbering) {
		$this->elements = new OoxmlElements();
		if ($styles !== null) {
			$this->loadStyles(styles: $styles);
		}

		if ($numbering !== null) {
			$this->loadNumbering(numbering: $numbering);
		}
	}//end __construct()

	/**
	 * Whether a paragraph is the title, a heading, a list item or plain text.
	 *
	 * An outline level on the paragraph itself overrides its style, 9 (body text)
	 * included. A heading beats a list: LibreOffice attaches its chapter
	 * numbering to the heading styles, and those paragraphs are headings.
	 *
	 * @param DOMElement $paragraph A `w:p` element.
	 *
	 * @return ParagraphKind The kind; `level` is the heading level (1 to 9) or the list level (1 and up).
	 *
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 * @spec openspec/specs/text-extraction-document/spec.md#requirement-lists-keep-their-items-levels-and-kind-req-docx-004
	 */
	public function classify(DOMElement $paragraph): array {
		$properties = $this->elements->child(parent: $paragraph, localName: 'pPr');
		$styleId = $this->elements->childValue(parent: $properties, localName: 'pStyle');
		$outline = $this->elements->integer(value: $this->elements->childValue(parent: $properties, localName: 'outlineLvl'));

		$heading = null;
		if ($outline !== null) {
			$heading = $this->headingFromOutline(outline: $outline);
		}

		if ($outline === null) {
			$heading = $this->styleHeading(styleId: $styleId);
		}

		return ($heading ?? $this->listOrText(properties: $properties, styleId: $styleId));
	}//end classify()

	/**
	 * The title or heading a paragraph style makes a paragraph, following the style chain.
	 *
	 * @param string $styleId The paragraph style id, '' when the paragraph names none.
	 *
	 * @return ParagraphKind|null Null when the style is neither.
	 */
	private function styleHeading(string $styleId): ?array {
		if ($styleId !== '' && isset($this->styles[$styleId]) === false) {
			return $this->headingFromId(styleId: $styleId);
		}

		foreach ($this->styleChain(styleId: $styleId) as $style) {
			if ($style['name'] === 'title') {
				return ['kind' => 'title', 'level' => 1, 'numId' => '', 'ordered' => false];
			}

			if (preg_match('/^heading\s*([1-9])$/', $style['name'], $match) === 1) {
				return ['kind' => 'heading', 'level' => (int)$match[1], 'numId' => '', 'ordered' => false];
			}

			if ($style['outline'] !== null) {
				return $this->headingFromOutline(outline: $style['outline']);
			}
		}

		return null;
	}//end styleHeading()

	/**
	 * The heading a style id implies when the styles part does not define it (a hand-made package).
	 *
	 * @param string $styleId The style id.
	 *
	 * @return ParagraphKind|null
	 */
	private function headingFromId(string $styleId): ?array {
		if ($styleId === 'Title') {
			return ['kind' => 'title', 'level' => 1, 'numId' => '', 'ordered' => false];
		}

		if (preg_match('/^Heading([1-9])$/', $styleId, $match) === 1) {
			return ['kind' => 'heading', 'level' => (int)$match[1], 'numId' => '', 'ordered' => false];
		}

		return null;
	}//end headingFromId()

	/**
	 * The heading an outline level gives, or null for body text (9 and up).
	 *
	 * @param int $outline The outline level, 0 to 9.
	 *
	 * @return ParagraphKind|null
	 */
	private function headingFromOutline(int $outline): ?array {
		if ($outline > 8) {
			return null;
		}

		return ['kind' => 'heading', 'level' => ($outline + 1), 'numId' => '', 'ordered' => false];
	}//end headingFromOutline()

	/**
	 * A list item when the paragraph or its style carries numbering, else plain text.
	 *
	 * @param DOMElement|null $properties The paragraph's `w:pPr`, or null.
	 * @param string $styleId The paragraph style id.
	 *
	 * @return ParagraphKind
	 */
	private function listOrText(?DOMElement $properties, string $styleId): array {
		$numbering = $this->elements->child(parent: $properties, localName: 'numPr');
		$numId = $this->elements->childValue(parent: $numbering, localName: 'numId');
		$level = $this->elements->integer(value: $this->elements->childValue(parent: $numbering, localName: 'ilvl'));

		foreach ($this->styleChain(styleId: $styleId) as $style) {
			if ($numId === '') {
				$numId = $style['numId'];
			}

			$level = ($level ?? $style['ilvl']);
		}

		// Numbering id 0 removes numbering that a style would otherwise apply.
		if ($numId === '' || $numId === '0') {
			return ['kind' => 'text', 'level' => 0, 'numId' => '', 'ordered' => false];
		}

		$level = ($level ?? 0);

		return ['kind' => 'list', 'level' => ($level + 1), 'numId' => $numId, 'ordered' => $this->isOrdered(numId: $numId, level: $level)];
	}//end listOrText()

	/**
	 * Whether a list level is numbered: any number format except bullet and none.
	 *
	 * @param string $numId The numbering instance id.
	 * @param int $level The 0-based list level.
	 *
	 * @return bool False when the definition cannot be resolved.
	 */
	private function isOrdered(string $numId, int $level): bool {
		$abstractId = ($this->abstractIds[$numId] ?? '');
		$format = ($this->formats[$abstractId][$level] ?? '');

		return in_array($format, self::UNORDERED_FORMATS, true) === false;
	}//end isOrdered()

	/**
	 * The style and its `basedOn` ancestors, nearest first, bounded and cycle-safe.
	 *
	 * @param string $styleId The style to start from, '' for none.
	 *
	 * @return list<StyleEntry>
	 */
	private function styleChain(string $styleId): array {
		$chain = [];
		$steps = 0;
		while (isset($this->styles[$styleId]) === true && isset($chain[$styleId]) === false && $steps < self::MAX_STYLE_CHAIN) {
			$chain[$styleId] = $this->styles[$styleId];
			$styleId = $this->styles[$styleId]['basedOn'];
			$steps++;
		}

		return array_values($chain);
	}//end styleChain()

	/**
	 * Read the paragraph styles: name, parent, outline level and numbering.
	 *
	 * @param DOMDocument $styles The parsed styles part.
	 *
	 * @return void
	 */
	private function loadStyles(DOMDocument $styles): void {
		foreach ($styles->getElementsByTagNameNS('*', 'style') as $style) {
			if ($this->elements->attribute(element: $style, localName: 'type') !== 'paragraph') {
				continue;
			}

			$properties = $this->elements->child(parent: $style, localName: 'pPr');
			$numbering = $this->elements->child(parent: $properties, localName: 'numPr');

			$this->styles[$this->elements->attribute(element: $style, localName: 'styleId')] = [
				'name' => strtolower(trim($this->elements->childValue(parent: $style, localName: 'name'))),
				'basedOn' => $this->elements->childValue(parent: $style, localName: 'basedOn'),
				'outline' => $this->elements->integer(value: $this->elements->childValue(parent: $properties, localName: 'outlineLvl')),
				'numId' => $this->elements->childValue(parent: $numbering, localName: 'numId'),
				'ilvl' => $this->elements->integer(value: $this->elements->childValue(parent: $numbering, localName: 'ilvl')),
			];
		}
	}//end loadStyles()

	/**
	 * Read the numbering instances and the number format of every abstract level.
	 *
	 * @param DOMDocument $numbering The parsed numbering part.
	 *
	 * @return void
	 */
	private function loadNumbering(DOMDocument $numbering): void {
		foreach ($numbering->getElementsByTagNameNS('*', 'num') as $instance) {
			$numId = $this->elements->attribute(element: $instance, localName: 'numId');
			$this->abstractIds[$numId] = $this->elements->childValue(parent: $instance, localName: 'abstractNumId');
		}

		foreach ($numbering->getElementsByTagNameNS('*', 'abstractNum') as $abstract) {
			$abstractId = $this->elements->attribute(element: $abstract, localName: 'abstractNumId');
			foreach ($this->elements->children(parent: $abstract, localName: 'lvl') as $level) {
				$index = $this->elements->integer(value: $this->elements->attribute(element: $level, localName: 'ilvl'));
				if ($index !== null) {
					$this->formats[$abstractId][$index] = $this->elements->childValue(parent: $level, localName: 'numFmt');
				}
			}
		}
	}//end loadNumbering()
}//end class
