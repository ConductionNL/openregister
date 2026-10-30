<?php

/**
 * OpenRegister VisibleText
 *
 * Reads the visible text of an HTML element.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Odt;

use DOMElement;
use DOMNode;

/**
 * The text of an HTML element as a reader sees it: whitespace collapsed,
 * without the content of `script` and `style` elements.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class VisibleText {

	/**
	 * The visible text of an element.
	 *
	 * @param DOMNode $node The element.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function read(DOMNode $node): string {
		return trim((string) preg_replace('/\s+/u', ' ', $this->raw(node: $node)));
	}//end read()

	/**
	 * The text below a node, before whitespace is collapsed.
	 *
	 * @param DOMNode $node The node.
	 *
	 * @return string
	 */
	private function raw(DOMNode $node): string {
		$text = '';
		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_TEXT_NODE) {
				$text .= $child->textContent;
			}

			if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['script', 'style'], true) === false) {
				$text .= $this->raw(node: $child);
			}
		}

		return $text;
	}//end raw()
}//end class
