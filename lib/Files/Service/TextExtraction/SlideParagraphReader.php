<?php

/**
 * OpenRegister slide paragraph reader
 *
 * Reads the non-empty paragraphs of a DrawingML text body (`txBody`): every
 * text run in order, a line break as a space, whitespace collapsed. Used by
 * PresentationSlideParser for slide shapes, table cells and notes.
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

use DOMElement;
use DOMNode;

/**
 * Reads the paragraphs of a DrawingML text body.
 *
 * @spec openspec/specs/text-extraction-presentation/spec.md#requirement-each-slide-carries-its-title-and-its-body-text-in-shape-order-req-pptx-002
 */
final class SlideParagraphReader {

	/**
	 * The non-empty paragraphs of a text body, runs joined and whitespace collapsed.
	 *
	 * @param DOMElement|null $textBody A `txBody` element, or null.
	 *
	 * @return list<string>
	 */
	public function read(?DOMElement $textBody): array {
		if ($textBody === null) {
			return [];
		}

		$paragraphs = [];
		foreach ($textBody->childNodes as $child) {
			$text = $this->paragraphNodeText(node: $child);
			if ($text !== '') {
				$paragraphs[] = $text;
			}
		}

		return $paragraphs;
	}//end read()

	/**
	 * The text of a text-body child when it is a paragraph, else ''.
	 *
	 * @param DOMNode $node A child of a `txBody` element.
	 *
	 * @return string
	 */
	private function paragraphNodeText(DOMNode $node): string {
		if (($node instanceof DOMElement) === false || $node->localName !== 'p') {
			return '';
		}

		return $this->paragraphText(paragraph: $node);
	}//end paragraphNodeText()

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
}//end class
