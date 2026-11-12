<?php

/**
 * RelsPrune
 *
 * The Relationship entries to drop from one OPC rels part: those whose
 * Target basename names a removed part, and the hyperlink relationships
 * whose Id belongs to a flattened hyperlink.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

use DOMDocument;
use DOMElement;

/**
 * Relationship entries to drop from one rels part.
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
final readonly class RelsPrune {
	/**
	 * Constructor.
	 *
	 * @param string $relsName The rels part path.
	 * @param string[] $targetBasenames Target basenames to drop (e.g. comments.xml, item1.xml).
	 * @param string[] $hyperlinkIds Hyperlink relationship IDs to drop.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		public string $relsName,
		public array $targetBasenames,
		public array $hyperlinkIds,
	) {
	}//end __construct()

	/**
	 * Whether there is nothing to drop.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function isEmpty(): bool {
		return $this->targetBasenames === [] && $this->hyperlinkIds === [];
	}//end isEmpty()

	/**
	 * Drop the matching Relationship entries from a loaded rels DOM.
	 *
	 * @param DOMDocument $dom The rels part DOM.
	 *
	 * @return bool True when at least one entry was dropped.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function applyTo(DOMDocument $dom): bool {
		$changed = false;
		foreach (iterator_to_array($dom->getElementsByTagName('Relationship')) as $rel) {
			if ($this->matches(rel: $rel) === true) {
				$rel->parentNode?->removeChild($rel);
				$changed = true;
			}
		}

		return $changed;
	}//end applyTo()

	/**
	 * Whether one Relationship entry is to be dropped (by target OR by hyperlink id).
	 *
	 * @param DOMElement $rel The Relationship element.
	 *
	 * @return bool
	 */
	private function matches(DOMElement $rel): bool {
		if (in_array(basename($rel->getAttribute('Target')), $this->targetBasenames, true) === true) {
			return true;
		}

		return str_ends_with($rel->getAttribute('Type'), '/hyperlink') === true
			&& in_array($rel->getAttribute('Id'), $this->hyperlinkIds, true) === true;
	}//end matches()
}//end class
