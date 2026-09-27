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
 * @spec openspec/changes/pptx-structured-reader/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use DOMDocument;
use DOMElement;

/**
 * Reads title, body, pictures and notes out of PresentationML slide XML.
 *
 * @psalm-type SlideImage = array{target: string, external: bool, name: string, description: string}
 * @psalm-type SlideContent = array{titles: list<string>, body: list<string>, images: list<SlideImage>}
 *
 * @spec openspec/changes/pptx-structured-reader/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
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
	 * The relationship ids of the slides, in the order the deck presents them.
	 *
	 * @param DOMDocument $presentation The parsed presentation part.
	 *
	 * @return list<string> Relationship ids, e.g. `rId2`, in deck order.
	 *
	 * @spec openspec/changes/pptx-structured-reader/specs/text-extraction-presentation/spec.md#requirement-slides-come-back-in-presentation-order-req-pptx-001
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
	 * @spec openspec/changes/pptx-structured-reader/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
	 * @spec openspec/changes/pptx-structured-reader/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-image-references-in-shape-order-req-pptx-004
	 */
	public function parseSlide(DOMDocument $slide, array $relationships): array {
		$content = ['titles' => [], 'body' => [], 'images' => []];
		$tree = $this->firstDescendant(element: $slide, localName: 'spTree');
		if ($tree !== null) {
			$this->walk(container: $tree, relationships: $relationships, content: $content, depth: 0);
		}

		return [
			'hidden' => in_array((string)$slide->documentElement?->getAttribute('show'), ['0', 'false'], true),
			'title' => implode(' ', $content['titles']),
			'body' => $content['body'],
			'images' => $content['images'],
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
	 * @spec openspec/changes/pptx-structured-reader/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-speaker-notes-req-pptx-003
	 */
	public function parseNotes(DOMDocument $notes): string {
		$paragraphs = [];
		foreach ($notes->getElementsByTagNameNS('*', 'sp') as $shape) {
			if (in_array($this->placeholderType(shape: $shape), self::FURNITURE_TYPES, true) === true) {
				continue;
			}

			array_push($paragraphs, ...$this->paragraphs(textBody: $this->child(parent: $shape, localName: 'txBody')));
		}

		return implode("\n", $paragraphs);
	}//end parseNotes()

	/**
	 * Walk the shapes of a container (the shape tree or a group) in order.
	 *
	 * @param DOMElement $container The shape tree, a group, or a markup-compatibility branch.
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The slide's relationships.
	 * @param SlideContent $content The accumulated content, extended in place.
	 * @param int $depth How many groups deep this container sits.
	 *
	 * @return void
	 */
	private function walk(DOMElement $container, array $relationships, array &$content, int $depth): void {
		if ($depth > self::MAX_GROUP_DEPTH) {
			return;
		}

		foreach ($container->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$this->visit(shape: $child, relationships: $relationships, content: $content, depth: $depth);
			}
		}
	}//end walk()

	/**
	 * Take what one shape contributes: text, table cells, a picture, or a nested walk.
	 *
	 * @param DOMElement $shape The shape element.
	 * @param array<string, array{type: string, target: string, external: bool}> $relationships The slide's relationships.
	 * @param SlideContent $content The accumulated content, extended in place.
	 * @param int $depth How many groups deep the shape sits.
	 *
	 * @return void
	 */
	private function visit(DOMElement $shape, array $relationships, array &$content, int $depth): void {
		if ($shape->localName === 'sp') {
			$this->collectText(shape: $shape, content: $content);
			return;
		}

		if ($shape->localName === 'grpSp') {
			$this->walk(container: $shape, relationships: $relationships, content: $content, depth: ($depth + 1));
			return;
		}

		if ($shape->localName === 'graphicFrame') {
			foreach ($shape->getElementsByTagNameNS('*', 'tc') as $cell) {
				array_push($content['body'], ...$this->paragraphs(textBody: $this->child(parent: $cell, localName: 'txBody')));
			}

			return;
		}

		if ($shape->localName === 'pic') {
			$content['images'][] = $this->picture(picture: $shape, relationships: $relationships);
			return;
		}

		if ($shape->localName === 'AlternateContent') {
			// Take one branch only, so the same shape is never read twice.
			$branch = ($this->child(parent: $shape, localName: 'Fallback') ?? $this->child(parent: $shape, localName: 'Choice'));
			if ($branch !== null) {
				$this->walk(container: $branch, relationships: $relationships, content: $content, depth: ($depth + 1));
			}
		}
	}//end visit()

	/**
	 * Add a text shape's paragraphs to the title or the body; skip page furniture.
	 *
	 * @param DOMElement $shape A `sp` element.
	 * @param SlideContent $content The accumulated content, extended in place.
	 *
	 * @return void
	 */
	private function collectText(DOMElement $shape, array &$content): void {
		$type = $this->placeholderType(shape: $shape);
		if (in_array($type, self::FURNITURE_TYPES, true) === true) {
			return;
		}

		$paragraphs = $this->paragraphs(textBody: $this->child(parent: $shape, localName: 'txBody'));
		if (in_array($type, self::TITLE_TYPES, true) === false) {
			array_push($content['body'], ...$paragraphs);
			return;
		}

		if ($paragraphs !== []) {
			$content['titles'][] = implode(' ', $paragraphs);
		}
	}//end collectText()

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
	 * The non-empty paragraphs of a text body, runs joined and whitespace collapsed.
	 *
	 * @param DOMElement|null $textBody A `txBody` element, or null.
	 *
	 * @return list<string>
	 */
	private function paragraphs(?DOMElement $textBody): array {
		if ($textBody === null) {
			return [];
		}

		$paragraphs = [];
		foreach ($textBody->childNodes as $child) {
			if (($child instanceof DOMElement) === false || $child->localName !== 'p') {
				continue;
			}

			$text = $this->paragraphText(paragraph: $child);
			if ($text !== '') {
				$paragraphs[] = $text;
			}
		}

		return $paragraphs;
	}//end paragraphs()

	/**
	 * The text of one paragraph: every text run in order, a line break as a space.
	 *
	 * @param DOMElement $paragraph A `p` element.
	 *
	 * @return string
	 */
	private function paragraphText(DOMElement $paragraph): string {
		$text = '';
		foreach ($paragraph->getElementsByTagNameNS('*', '*') as $node) {
			if ($node->localName === 't') {
				$text .= $node->textContent;
			}

			if ($node->localName === 'br') {
				$text .= ' ';
			}
		}

		return trim((string)preg_replace('/\s+/u', ' ', $text));
	}//end paragraphText()

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
			foreach ($element->attributes as $attribute) {
				if ($attribute->localName === $localName && str_ends_with((string)$attribute->namespaceURI, '/relationships') === true) {
					return (string)$attribute->value;
				}
			}
		}

		return '';
	}//end relationshipAttribute()

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
