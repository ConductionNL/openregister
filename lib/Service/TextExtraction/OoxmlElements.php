<?php

/**
 * OpenRegister Office Open XML element access
 *
 * Small, stateless DOM lookups that every WordprocessingML reader needs:
 * children and attributes by local name in any namespace, the `w:val` of a
 * property element, and relationship-namespace attributes. Matching by local
 * name lets a transitional and a strict OOXML package (ECMA-376 part 1, which
 * use different namespace URIs for the same elements) read the same way. Used
 * by DocumentStyleMap and DocumentContentReader (docx-structured-reader).
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
 * Local-name lookups on OOXML elements.
 *
 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
 */
class OoxmlElements {

	/**
	 * The direct children with the given local name, in order.
	 *
	 * @param DOMElement|null $parent The parent, or null.
	 * @param string $localName The local name.
	 *
	 * @return list<DOMElement>
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 */
	public function children(?DOMElement $parent, string $localName): array {
		if ($parent === null) {
			return [];
		}

		$children = [];
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === $localName) {
				$children[] = $child;
			}
		}

		return $children;
	}//end children()

	/**
	 * The first direct child with the given local name, or null.
	 *
	 * @param DOMElement|null $parent The parent, or null.
	 * @param string $localName The local name.
	 *
	 * @return DOMElement|null
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 */
	public function child(?DOMElement $parent, string $localName): ?DOMElement {
		return ($this->children(parent: $parent, localName: $localName)[0] ?? null);
	}//end child()

	/**
	 * The first descendant with the given local name, or null.
	 *
	 * @param DOMDocument|DOMElement $root The document or element to search under.
	 * @param string $localName The local name.
	 *
	 * @return DOMElement|null
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-image-references-come-back-in-document-order-req-docx-006
	 */
	public function firstDescendant(DOMDocument|DOMElement $root, string $localName): ?DOMElement {
		$found = $root->getElementsByTagNameNS('*', $localName)->item(0);
		if ($found instanceof DOMElement) {
			return $found;
		}

		return null;
	}//end firstDescendant()

	/**
	 * An attribute by local name in any namespace, or '' when absent.
	 *
	 * @param DOMElement|null $element The element, or null.
	 * @param string $localName The attribute's local name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 */
	public function attribute(?DOMElement $element, string $localName): string {
		if ($element === null) {
			return '';
		}

		foreach ($element->attributes as $attribute) {
			if ($attribute->localName === $localName) {
				return (string)$attribute->value;
			}
		}

		return '';
	}//end attribute()

	/**
	 * The `val` attribute of the first direct child with the given local name, or '' (e.g. `w:pStyle`).
	 *
	 * @param DOMElement|null $parent The parent, or null.
	 * @param string $localName The child's local name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-content-comes-back-in-sections-under-their-heading-req-docx-001
	 */
	public function childValue(?DOMElement $parent, string $localName): string {
		return $this->attribute(element: $this->child(parent: $parent, localName: $localName), localName: 'val');
	}//end childValue()

	/**
	 * A non-negative integer from an attribute value, or null for '' or anything else.
	 *
	 * @param string $value The attribute value.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-lists-keep-their-items-levels-and-kind-req-docx-004
	 */
	public function integer(string $value): ?int {
		if ($value === '' || ctype_digit($value) === false) {
			return null;
		}

		return (int)$value;
	}//end integer()

	/**
	 * The first present relationship-namespace attribute (`r:embed`, `r:link`, `r:id`), in either OOXML flavour.
	 *
	 * @param DOMElement $element The element.
	 * @param list<string> $localNames The attribute local names to try, in order.
	 *
	 * @return string The value, or '' when none is present.
	 *
	 * @spec openspec/changes/docx-structured-reader/specs/text-extraction-document/spec.md#requirement-image-references-come-back-in-document-order-req-docx-006
	 */
	public function relationshipAttribute(DOMElement $element, array $localNames): string {
		foreach ($localNames as $localName) {
			foreach ($element->attributes as $attribute) {
				if ($attribute->localName === $localName && str_ends_with((string)$attribute->namespaceURI, '/relationships') === true) {
					return (string)$attribute->value;
				}
			}
		}

		return '';
	}//end relationshipAttribute()
}//end class
