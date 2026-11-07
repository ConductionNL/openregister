<?php

/**
 * OpenRegister presentation slide parser
 *
 * Turns the parsed XML of one PresentationML slide (ECMA-376 part 1, section 19)
 * into the structure a consumer needs to build a lesson block from it: the
 * title, the body paragraphs and the pictures, each in the order the shapes
 * sit on the slide. Also reads the notes body from a notes page and the slide
 * order from the presentation part. Pure DOM work with no I/O: the package
 * access and its bounds live in OoxmlPackage, the file handling in
 * PresentationExtractor (pptx-structured-reader).
 *
 * Elements are matched by local name, so a transitional and a strict OOXML
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
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use DOMDocument;
use DOMElement;

/**
 * Reads title, body, pictures and notes out of PresentationML slide XML.
 *
 * @psalm-type SlideImage = array{target: string, external: bool, name: string, description: string}
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
 */
class PresentationSlideParser {

	/**
	 * How deep nested groups are followed before the walk stops descending.
	 *
	 * @var int
	 */
	public const MAX_GROUP_DEPTH = 20;

	/**
	 * Placeholder types that hold the slide title.
	 *
	 * @var list<string>
	 */
	private const TITLE_TYPES = ['title', 'ctrTitle'];

	/**
	 * Placeholder types that are page furniture, not content.
	 *
	 * @var list<string>
	 */
	private const FURNITURE_TYPES = ['sldNum', 'dt', 'ftr', 'hdr', 'sldImg'];

	/**
	 * Reads the paragraphs of a text body.
	 *
	 * @var SlideParagraphReader
	 */
	private readonly SlideParagraphReader $paragraphReader;

	/**
	 * Build the parser with its paragraph reader.
	 */
	public function __construct() {
		$this->paragraphReader = new SlideParagraphReader();
	}//end __construct()

	/**
	 * The relationship ids of the slides, in the order the deck presents them.
	 *
	 * @param DOMDocument $presentation The parsed presentation part.
	 *
	 * @return list<string> Relationship ids, e.g. `rId2`, in deck order.
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-slides-come-back-in-presentation-order-req-pptx-001
	 */
	public function slideRelationshipIds(DOMDocument $presentation): array {
		$ids = [];
		foreach ($presentation->getElementsByTagNameNS('*', 'sldId') as $slideId) {
			$ids[] = $this->relationshipAttribute(element: $slideId, localNames: ['id']);
		}

		return $ids;
	}//end slideRelationshipIds()

	/**
	 * Parse one slide into its hidden flag, title, body paragraphs and pictures.
	 *
	 * @param DOMDocument $slide The parsed slide part.
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The slide's relationships.
	 *
	 * @return array{hidden: bool, title: string, body: list<string>, images: list<SlideImage>}
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-image-references-in-shape-order-req-pptx-004
	 */
	public function parseSlide(DOMDocument $slide, array $relationships): array {
		$walk = new SlideWalk(relationships: $relationships);
		$tree = $this->firstDescendant(element: $slide, localName: 'spTree');
		if ($tree !== null) {
			$this->walk(container: $tree, walk: $walk);
		}

		return [
			'hidden' => in_array((string)$slide->documentElement?->getAttribute('show'), ['0', 'false'], true),
			'title' => implode(' ', $walk->titles),
			'body' => $walk->body,
			'images' => $walk->images,
		];
	}//end parseSlide()

	/**
	 * The speaker notes on a notes page, paragraphs joined by a newline.
	 *
	 * Every text shape on the page counts except page furniture (slide image, slide
	 * number, header, footer, date). PowerPoint puts notes in a `body` placeholder;
	 * LibreOffice writes them as a plain text box; a teacher may add a second box.
	 *
	 * @param DOMDocument $notes The parsed notes part.
	 *
	 * @return string The notes, or '' when the page holds no notes text.
	 *
	 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-speaker-notes-req-pptx-003
	 */
	public function parseNotes(DOMDocument $notes): string {
		$paragraphs = [];
		foreach ($notes->getElementsByTagNameNS('*', 'sp') as $shape) {
			if (in_array($this->placeholderType(shape: $shape), self::FURNITURE_TYPES, true) === true) {
				continue;
			}

			array_push($paragraphs, ...$this->shapeParagraphs(parent: $shape));
		}

		return implode("\n", $paragraphs);
	}//end parseNotes()

	/**
	 * Walk the shapes of a container (the shape tree or a group) in order.
	 *
	 * @param DOMElement $container The shape tree, a group, or a markup-compatibility branch.
	 * @param SlideWalk  $walk      The walk state, extended in place.
	 *
	 * @return void
	 */
	private function walk(DOMElement $container, SlideWalk $walk): void {
		if ($walk->depth > self::MAX_GROUP_DEPTH) {
			return;
		}

		foreach ($container->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$this->visit(shape: $child, walk: $walk);
			}
		}
	}//end walk()

	/**
	 * Walk a nested container one group deeper.
	 *
	 * @param DOMElement $container The group or markup-compatibility branch.
	 * @param SlideWalk  $walk      The walk state, extended in place.
	 *
	 * @return void
	 */
	private function descend(DOMElement $container, SlideWalk $walk): void {
		$walk->depth++;
		$this->walk(container: $container, walk: $walk);
		$walk->depth--;
	}//end descend()

	/**
	 * Take what one shape contributes: text, table cells, a picture, or a nested walk.
	 *
	 * @param DOMElement $shape The shape element.
	 * @param SlideWalk  $walk  The walk state, extended in place.
	 *
	 * @return void
	 */
	private function visit(DOMElement $shape, SlideWalk $walk): void {
		if (in_array($shape->localName, ['sp', 'pic', 'graphicFrame'], true) === true) {
			$this->collectLeaf(shape: $shape, walk: $walk);
			return;
		}

		if ($shape->localName === 'grpSp') {
			$this->descend(container: $shape, walk: $walk);
			return;
		}

		if ($shape->localName === 'AlternateContent') {
			$this->descendAlternate(alternate: $shape, walk: $walk);
		}
	}//end visit()

	/**
	 * Take what a leaf shape contributes: text, a picture, or table cells.
	 *
	 * @param DOMElement $shape A `sp`, `pic` or `graphicFrame` element.
	 * @param SlideWalk  $walk  The walk state, extended in place.
	 *
	 * @return void
	 */
	private function collectLeaf(DOMElement $shape, SlideWalk $walk): void {
		if ($shape->localName === 'sp') {
			$this->collectText(shape: $shape, walk: $walk);
			return;
		}

		if ($shape->localName === 'pic') {
			$walk->images[] = $this->picture(picture: $shape, relationships: $walk->relationships);
			return;
		}

		foreach ($shape->getElementsByTagNameNS('*', 'tc') as $cell) {
			array_push($walk->body, ...$this->shapeParagraphs(parent: $cell));
		}
	}//end collectLeaf()

	/**
	 * Walk one branch of a markup-compatibility block: the fallback, else the choice.
	 *
	 * Taking one branch only means the same shape is never read twice.
	 *
	 * @param DOMElement $alternate An `AlternateContent` element.
	 * @param SlideWalk  $walk      The walk state, extended in place.
	 *
	 * @return void
	 */
	private function descendAlternate(DOMElement $alternate, SlideWalk $walk): void {
		$branch = ($this->child(parent: $alternate, localName: 'Fallback') ?? $this->child(parent: $alternate, localName: 'Choice'));
		if ($branch !== null) {
			$this->descend(container: $branch, walk: $walk);
		}
	}//end descendAlternate()

	/**
	 * Add a text shape's paragraphs to the title or the body; skip page furniture.
	 *
	 * @param DOMElement $shape A `sp` element.
	 * @param SlideWalk  $walk  The walk state, extended in place.
	 *
	 * @return void
	 */
	private function collectText(DOMElement $shape, SlideWalk $walk): void {
		$type = $this->placeholderType(shape: $shape);
		if (in_array($type, self::FURNITURE_TYPES, true) === true) {
			return;
		}

		$paragraphs = $this->shapeParagraphs(parent: $shape);
		if (in_array($type, self::TITLE_TYPES, true) === false) {
			array_push($walk->body, ...$paragraphs);
			return;
		}

		if ($paragraphs !== []) {
			$walk->titles[] = implode(' ', $paragraphs);
		}
	}//end collectText()

	/**
	 * The paragraphs of an element's direct `txBody` child.
	 *
	 * @param DOMElement $parent A shape or table cell.
	 *
	 * @return list<string>
	 */
	private function shapeParagraphs(DOMElement $parent): array {
		return $this->paragraphReader->read(textBody: $this->child(parent: $parent, localName: 'txBody'));
	}//end shapeParagraphs()

	/**
	 * A picture's package path (or link), whether it is linked, its name and its alt text.
	 *
	 * @param DOMElement $picture A `pic` element.
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The slide's relationships.
	 *
	 * @return SlideImage
	 */
	private function picture(DOMElement $picture, array $relationships): array {
		$properties = $this->firstDescendant(element: $picture, localName: 'cNvPr');
		$blip = $this->firstDescendant(element: $picture, localName: 'blip');

		// An embedded picture names its part in r:embed; a linked one names its URL in r:link.
		$relationshipId = $this->relationshipAttribute(element: $blip, localNames: ['embed', 'link']);
		$relationship = ($relationships[$relationshipId] ?? ['target' => '', 'external' => false]);

		return [
			'target' => $relationship['target'],
			'external' => $relationship['external'],
			'name' => (string)$properties?->getAttribute('name'),
			'description' => (string)$properties?->getAttribute('descr'),
		];
	}//end picture()

	/**
	 * A shape's placeholder type, '' for a plain shape or an untyped placeholder.
	 *
	 * @param DOMElement $shape A `sp` element.
	 *
	 * @return string E.g. `title`, `body`, `sldNum`.
	 */
	private function placeholderType(DOMElement $shape): string {
		$placeholder = $this->firstDescendant(element: ($this->child(parent: $shape, localName: 'nvSpPr') ?? $shape), localName: 'ph');
		if ($placeholder === null) {
			return '';
		}

		return $placeholder->getAttribute('type');
	}//end placeholderType()

	/**
	 * The first present relationship-namespace attribute (`r:id`, `r:embed`, `r:link`), in either OOXML flavour.
	 *
	 * @param DOMElement|null $element The element, or null.
	 * @param list<string> $localNames The attribute local names to try, in order.
	 *
	 * @return string The value, or '' when none is present.
	 */
	private function relationshipAttribute(?DOMElement $element, array $localNames): string {
		if ($element === null) {
			return '';
		}

		foreach ($localNames as $localName) {
			$value = $this->relationshipAttributeNamed(element: $element, localName: $localName);
			if ($value !== null) {
				return $value;
			}
		}

		return '';
	}//end relationshipAttribute()

	/**
	 * The value of one relationship-namespace attribute, in either OOXML flavour.
	 *
	 * @param DOMElement $element   The element.
	 * @param string     $localName The attribute local name.
	 *
	 * @return string|null The value, or null when the attribute is absent.
	 */
	private function relationshipAttributeNamed(DOMElement $element, string $localName): ?string {
		foreach ($element->attributes as $attribute) {
			if ($attribute->localName === $localName && str_ends_with((string)$attribute->namespaceURI, '/relationships') === true) {
				return (string)$attribute->value;
			}
		}

		return null;
	}//end relationshipAttributeNamed()

	/**
	 * The first direct child with the given local name, or null.
	 *
	 * @param DOMElement $parent The parent.
	 * @param string $localName The local name.
	 *
	 * @return DOMElement|null
	 */
	private function child(DOMElement $parent, string $localName): ?DOMElement {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === $localName) {
				return $child;
			}
		}

		return null;
	}//end child()

	/**
	 * The first descendant with the given local name, or null.
	 *
	 * @param DOMDocument|DOMElement $element The document or element to search under.
	 * @param string $localName The local name.
	 *
	 * @return DOMElement|null
	 */
	private function firstDescendant(DOMDocument|DOMElement $element, string $localName): ?DOMElement {
		$found = $element->getElementsByTagNameNS('*', $localName)->item(0);
		if ($found instanceof DOMElement) {
			return $found;
		}

		return null;
	}//end firstDescendant()
}//end class
