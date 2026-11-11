<?php

/**
 * OpenRegister MDTO Document Writer
 *
 * The XML primitives both MDTO document kinds are built from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Builds the MDTO document shell and the XSD's reusable groups.
 *
 * Knows XML and MDTO-XML 1.0.1, and nothing about openregister objects. Each
 * method writes one of the XSD's named types exactly as the schema declares
 * it, so an element built here is shaped correctly regardless of who calls.
 *
 * @psalm-suppress UnusedClass
 */
class MdtoDocumentWriter {

	/**
	 * MDTO namespace URI.
	 */
	public const MDTO_NAMESPACE = 'https://www.nationaalarchief.nl/mdto';

	/**
	 * MDTO namespace prefix.
	 */
	public const MDTO_PREFIX = 'mdto';

	/**
	 * The schema version this writer targets, published as a URL.
	 *
	 * The MDTO SIP specification requires every sidecar to carry the MDTO
	 * version it follows; the Nationaal Archief's own example documents do it
	 * through `xsi:schemaLocation`, pointing at this URL.
	 */
	public const SCHEMA_LOCATION = 'https://www.nationaalarchief.nl/mdto/MDTO-XML1.0.1.xsd';

	/**
	 * XML Schema instance namespace.
	 */
	private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

	/**
	 * Create an MDTO document and its single content element.
	 *
	 * The XSD declares one global element, `MDTO`, whose content is a CHOICE
	 * of exactly one `informatieobject` or one `bestand`. So a document holds
	 * one of them and never both, which is why a file gets its own document.
	 *
	 * @param string $kind Either `informatieobject` or `bestand`.
	 *
	 * @return array{0: DOMDocument, 1: DOMElement} The document and the content element to fill.
	 *
	 * @throws InvalidArgumentException When the kind is neither.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function createDocument(string $kind): array {
		if ($kind !== 'informatieobject' && $kind !== 'bestand') {
			throw new InvalidArgumentException('An MDTO document holds an informatieobject or a bestand, not ' . $kind);
		}

		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;

		$root = self::rootElement(dom: $dom);
		$dom->appendChild($root);

		$content = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':' . $kind);
		$root->appendChild($content);

		return [$dom, $content];
	}//end createDocument()

	/**
	 * Serialise a document built by {@see self::createDocument()}.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return string The XML.
	 *
	 * @throws InvalidArgumentException When serialisation fails.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function serialise(DOMDocument $dom): string {
		$xml = $dom->saveXML();
		if ($xml === false) {
			throw new InvalidArgumentException('Failed to serialise the MDTO document');
		}

		return $xml;
	}//end serialise()

	/**
	 * Add a simple text element.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name, without prefix.
	 * @param string $content The text content.
	 *
	 * @return DOMElement The new element.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function text(DOMElement $parent, string $name, string $content): DOMElement {
		$element = $this->element(parent: $parent, name: $name);
		$element->textContent = $content;

		return $element;
	}//end text()

	/**
	 * Add text elements, leaving out each one that has no content.
	 *
	 * MDTO's rule for an optional element is the same everywhere: write it
	 * when the object supplies a value, omit it entirely otherwise, never an
	 * empty element. Having it in one place keeps that rule from drifting
	 * between the elements that follow it.
	 *
	 * @param DOMElement                $parent The parent element.
	 * @param array<string, string|null> $texts  Element name (without prefix) to text content, in order;
	 *                                           a null content writes nothing for that element.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function textsIfPresent(DOMElement $parent, array $texts): void {
		foreach ($texts as $name => $content) {
			if ($content !== null) {
				$this->text(parent: $parent, name: (string)$name, content: $content);
			}
		}
	}//end textsIfPresent()

	/**
	 * Add an empty element to be filled by the caller.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name, without prefix.
	 *
	 * @return DOMElement The new element.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function element(DOMElement $parent, string $name): DOMElement {
		$document = $parent->ownerDocument;
		if ($document === null) {
			throw new InvalidArgumentException('Cannot add an MDTO element to a detached node');
		}

		$element = $document->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':' . $name);
		$parent->appendChild($element);

		return $element;
	}//end element()

	/**
	 * Add a `begripGegevens` element.
	 *
	 * The XSD makes `begripLabel` and `begripBegrippenlijst` both
	 * `minOccurs="1"`, so the list name is never optional.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name, without prefix.
	 * @param MdtoBegrip $begrip The term.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function begrip(DOMElement $parent, string $name, MdtoBegrip $begrip): void {
		$this->fillBegrip(element: $this->element(parent: $parent, name: $name), begrip: $begrip);
	}//end begrip()

	/**
	 * Fill an empty `begripGegevens` element with the term's label, code and list.
	 *
	 * @param DOMElement $element The element, as {@see element()} created it.
	 * @param MdtoBegrip $begrip  The term.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function fillBegrip(DOMElement $element, MdtoBegrip $begrip): void {
		$this->text(parent: $element, name: 'begripLabel', content: $begrip->label);
		if (($begrip->code ?? '') !== '') {
			$this->text(parent: $element, name: 'begripCode', content: (string)$begrip->code);
		}

		$this->fillVerwijzing(element: $this->element(parent: $element, name: 'begripBegrippenlijst'), verwijzing: new MdtoVerwijzing(naam: $begrip->list));
	}//end fillBegrip()

	/**
	 * The `MDTO` root element with its schema location.
	 *
	 * @param DOMDocument $dom The document.
	 *
	 * @return DOMElement The root.
	 */
	private static function rootElement(DOMDocument $dom): DOMElement {
		$root = $dom->createElementNS(self::MDTO_NAMESPACE, self::MDTO_PREFIX . ':MDTO');
		$root->setAttributeNS(self::XSI_NAMESPACE, 'xsi:schemaLocation', self::MDTO_NAMESPACE . ' ' . self::SCHEMA_LOCATION);

		return $root;
	}//end rootElement()

	/**
	 * Add a `verwijzingGegevens` element.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name, without prefix.
	 * @param MdtoVerwijzing $verwijzing The reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function verwijzing(DOMElement $parent, string $name, MdtoVerwijzing $verwijzing): void {
		$this->fillVerwijzing(element: $this->element(parent: $parent, name: $name), verwijzing: $verwijzing);
	}//end verwijzing()

	/**
	 * Fill an empty `verwijzingGegevens` element with the reference's name and, when known, its identification.
	 *
	 * @param DOMElement     $element    The element, as {@see element()} created it.
	 * @param MdtoVerwijzing $verwijzing The reference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function fillVerwijzing(DOMElement $element, MdtoVerwijzing $verwijzing): void {
		$this->text(parent: $element, name: 'verwijzingNaam', content: $verwijzing->naam);
		if (($verwijzing->kenmerk ?? '') !== '' && ($verwijzing->bron ?? '') !== '') {
			$this->identificatie(parent: $element, name: 'verwijzingIdentificatie', kenmerk: (string)$verwijzing->kenmerk, bron: (string)$verwijzing->bron);
		}
	}//end fillVerwijzing()

	/**
	 * Add an `identificatieGegevens` element.
	 *
	 * @param DOMElement $parent The parent element.
	 * @param string $name The element name, without prefix.
	 * @param string $kenmerk The identificatieKenmerk.
	 * @param string $bron The identificatieBron.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function identificatie(DOMElement $parent, string $name, string $kenmerk, string $bron): void {
		$element = $this->element(parent: $parent, name: $name);
		$this->text(parent: $element, name: 'identificatieKenmerk', content: $kenmerk);
		$this->text(parent: $element, name: 'identificatieBron', content: $bron);
	}//end identificatie()
}//end class
