<?php

/**
 * OpenRegister DocxTextMatch
 *
 * One search-and-replace over the text elements of a DOCX paragraph.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DOMElement;

/**
 * Finds a search text, case-insensitively, in the joined text of a list of
 * text elements and splices the replacement in.
 *
 * The replacement goes into the element where the match starts; the matched
 * characters are cut from that element and from any following elements the
 * match spans. Each search resumes after the previous replacement, so a
 * replacement that contains its own search text is not matched again.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class DocxTextMatch {

	/**
	 * Character offset in the joined text where the next search starts.
	 *
	 * @var integer
	 */
	private int $offset = 0;

	/**
	 * Character offset of the last match found.
	 *
	 * @var integer
	 */
	private int $start = 0;

	/**
	 * Constructor.
	 *
	 * @param string $search      The text to find; not empty.
	 * @param string $replacement The text to put in its place.
	 */
	public function __construct(
		private readonly string $search,
		private readonly string $replacement,
	) {
	}//end __construct()

	/**
	 * Find the next match in the joined text of the elements.
	 *
	 * @param list<DOMElement> $nodes Text elements in document order.
	 *
	 * @return bool Whether a match was found.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function findIn(array $nodes): bool {
		$text = '';
		foreach ($nodes as $node) {
			$text .= $node->textContent;
		}

		$position = mb_stripos($text, $this->search, $this->offset, 'UTF-8');
		if ($position === false) {
			return false;
		}

		$this->start = $position;
		return true;
	}//end findIn()

	/**
	 * Splice the replacement in at the match {@see findIn()} found last.
	 *
	 * @param list<DOMElement> $nodes The same text elements.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function applyTo(array $nodes): void {
		$remaining = mb_strlen($this->search, 'UTF-8');
		$insert = $this->replacement;
		$nodeEnd = 0;

		foreach ($nodes as $node) {
			$text = $node->textContent;
			$nodeStart = $nodeEnd;
			$nodeEnd += mb_strlen($text, 'UTF-8');
			if ($remaining === 0 || $this->start >= $nodeEnd) {
				continue;
			}

			$from = max(0, ($this->start - $nodeStart));
			$take = min($remaining, (mb_strlen($text, 'UTF-8') - $from));
			$node->textContent = mb_substr($text, 0, $from, 'UTF-8') . $insert . mb_substr($text, ($from + $take), null, 'UTF-8');
			$node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');

			$remaining -= $take;
			$insert = '';
		}

		$this->offset = ($this->start + mb_strlen($this->replacement, 'UTF-8'));
	}//end applyTo()
}//end class
