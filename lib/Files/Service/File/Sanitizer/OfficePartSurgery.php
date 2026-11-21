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
use DOMElement;
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
	 * The OOXML custom properties namespace.
	 */
	private const NS_CUSTOM = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';

	/**
	 * The OOXML variant types namespace.
	 */
	private const NS_VT = 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';

	/**
	 * The variant types that hold text a mask can rewrite.
	 */
	private const TEXT_TYPES = ['lpwstr', 'lpstr', 'bstr'];
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

	/**
	 * Mask the descriptive fields in place, or replace them by the sentinel
	 * when the run does not mask.
	 *
	 * @param DOMDocument                    $dom    The part.
	 * @param array<int, array{string, string}> $fields Namespace and local name of each field.
	 * @param MetadataMask                   $mask   The run's substitution map.
	 *
	 * @return int The number of fields changed.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function maskElements(DOMDocument $dom, array $fields, MetadataMask $mask): int {
		if ($mask->isActive() === false) {
			return $this->scrubElements(dom: $dom, fields: $fields);
		}

		$count = 0;
		foreach ($fields as [$namespace, $local]) {
			$count += $this->maskNodes(nodes: $dom->getElementsByTagNameNS($namespace, $local), mask: $mask);
		}

		return $count;
	}//end maskElements()

	/**
	 * Mask the text of every node in place, or replace it by the sentinel
	 * when the run does not mask.
	 *
	 * @param iterable<DOMNode> $nodes The candidate nodes.
	 * @param MetadataMask      $mask  The run's substitution map.
	 *
	 * @return int The number of nodes changed.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function maskNodes(iterable $nodes, MetadataMask $mask): int {
		if ($mask->isActive() === false) {
			return $this->scrubNodes(nodes: $nodes);
		}

		$count = 0;
		foreach (iterator_to_array($nodes) as $node) {
			assert($node instanceof DOMNode);
			$masked = $mask->apply(text: $node->textContent);
			if ($masked !== $node->textContent) {
				$node->textContent = $masked;
				$count++;
			}
		}

		return $count;
	}//end maskNodes()

	/**
	 * Mask the text-typed OOXML custom properties (`docProps/custom.xml`) and
	 * remove, naming them, the ones whose value is not text.
	 *
	 * Without masking, text-typed values go to the sentinel and the others
	 * stay, as the sanitisers always did.
	 *
	 * @param DOMDocument  $dom  The custom properties part.
	 * @param MetadataMask $mask The run's substitution map.
	 *
	 * @return int The number of properties changed or removed.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-a-value-inside-a-metadata-field-is-masked-and-unmaskable-fields-are-named-req-rrs-004
	 */
	public function maskOoxmlCustomProperties(DOMDocument $dom, MetadataMask $mask): int {
		$properties = iterator_to_array($dom->getElementsByTagNameNS(self::NS_CUSTOM, 'property'));
		$count      = 0;
		foreach ($properties as $property) {
			assert($property instanceof DOMElement);
			$values = array_values(
				array_filter(
					iterator_to_array($property->childNodes),
					static fn (DOMNode $child): bool => $child instanceof DOMElement
				)
			);
			$textValues = array_values(
				array_filter(
					$values,
					static fn (DOMElement $value): bool => $value->namespaceURI === self::NS_VT
						&& in_array($value->localName, self::TEXT_TYPES, true)
				)
			);

			if (count($values) === count($textValues)) {
				$count += $this->maskNodes(nodes: $textValues, mask: $mask);
				continue;
			}

			if ($mask->isActive() === false) {
				continue;
			}

			$type = 'vt:' . (string) ($values[0]->localName ?? '');
			$mask->noteUnmaskable(
				field: $property->getAttribute('name'),
				reason: 'not a text value (' . $type . ')'
			);
			$property->parentNode?->removeChild($property);
			$count++;
		}//end foreach

		return $count;
	}//end maskOoxmlCustomProperties()
}//end class
