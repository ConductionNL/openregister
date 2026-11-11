<?php

/**
 * OfficePartSurgery
 *
 * The ZIP-and-XML steps both office sanitisers share: copying the source
 * package to its destination, loading a package part as a namespace-aware
 * DOM, unwrapping an element in place, and scrubbing non-empty text nodes
 * to the sentinel.
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
use DOMNode;
use OCA\OpenRegister\Exception\SanitizationException;

/**
 * Shared package-part steps for the DOCX and ODT sanitisers.
 *
 * Built by each sanitiser with its own sentinel.
 *
 * @spec openspec/specs/office-document-sanitization/spec.md
 */
final class OfficePartSurgery {
	/**
	 * Constructor.
	 *
	 * @param string $sentinel The sentinel string applied to scrubbed text.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function __construct(
		private readonly string $sentinel,
	) {
	}//end __construct()

	/**
	 * Copy the source document to the destination when the paths differ.
	 *
	 * @param string $sourcePath Path to the source document.
	 * @param string $destPath Path the sanitized document is written to.
	 *
	 * @return void
	 *
	 * @throws SanitizationException When the copy fails.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function copyToDestination(string $sourcePath, string $destPath): void {
		if ($sourcePath === $destPath) {
			return;
		}

		if (copy($sourcePath, $destPath) === false) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_INTERNAL,
				message: 'Could not copy source document to destination'
			);
		}
	}//end copyToDestination()

	/**
	 * Load XML into a namespace-aware DOMDocument (XXE-safe).
	 *
	 * @param string $xml The XML string.
	 * @param string $failureMessage The exception message when the part is not well-formed.
	 *
	 * @throws SanitizationException When the part is not well-formed XML.
	 *
	 * @return DOMDocument The loaded document.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function loadXml(string $xml, string $failureMessage): DOMDocument {
		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = true;
		$dom->formatOutput = false;
		// LIBXML_NONET blocks network access; PHP 8 + libxml >= 2.9 disables
		// external entity loading by default, keeping this XXE-safe.
		$loaded = $dom->loadXML($xml, (LIBXML_NONET | LIBXML_COMPACT));
		if ($loaded === false) {
			throw new SanitizationException(
				reason: SanitizationException::REASON_CORRUPT_ZIP,
				message: $failureMessage
			);
		}

		return $dom;
	}//end loadXml()

	/**
	 * Replace a node with its own child nodes (unwrap).
	 *
	 * @param DOMNode $node The node to unwrap.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function unwrap(DOMNode $node): void {
		$parent = $node->parentNode;
		if ($parent === null) {
			return;
		}

		while ($node->firstChild !== null) {
			$parent->insertBefore($node->firstChild, $node);
		}

		$parent->removeChild($node);
	}//end unwrap()

	/**
	 * Replace each present, non-empty namespaced element's text with the sentinel.
	 *
	 * @param DOMDocument $dom The document.
	 * @param array<int, array{0:string,1:string}> $fields Namespace-URI / local-name pairs.
	 *
	 * @return int The number of elements scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function scrubElements(DOMDocument $dom, array $fields): int {
		$count = 0;
		foreach ($fields as [$namespace, $local]) {
			$count += $this->scrubNodes(nodes: $dom->getElementsByTagNameNS($namespace, $local));
		}

		return $count;
	}//end scrubElements()

	/**
	 * Replace the text of every non-empty node with the sentinel.
	 *
	 * @param iterable<DOMNode> $nodes The candidate nodes.
	 *
	 * @return int The number of nodes scrubbed.
	 *
	 * @spec openspec/specs/office-document-sanitization/spec.md
	 */
	public function scrubNodes(iterable $nodes): int {
		$count = 0;
		foreach (iterator_to_array($nodes) as $node) {
			assert($node instanceof DOMNode);
			if (trim($node->textContent) !== '') {
				$node->textContent = $this->sentinel;
				$count++;
			}
		}

		return $count;
	}//end scrubNodes()
}//end class
