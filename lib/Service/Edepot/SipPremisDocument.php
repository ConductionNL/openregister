<?php

/**
 * OpenRegister SIP PREMIS Document
 *
 * The premis.xml of one SIP package.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use DOMDocument;
use DOMElement;

/**
 * Writes premis.xml: one creation event for the transfer and one file object
 * per content file with its path, SHA-256 fixity, size and format.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class SipPremisDocument {

	/**
	 * PREMIS namespace URI.
	 *
	 * @var string
	 */
	private const PREMIS_NAMESPACE = 'info:lc/xmlns/premis-v2';

	/**
	 * XML Schema instance namespace URI.
	 *
	 * @var string
	 */
	private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

	/**
	 * The document.
	 *
	 * @var DOMDocument
	 */
	private DOMDocument $dom;

	/**
	 * The root element.
	 *
	 * @var DOMElement
	 */
	private DOMElement $premis;

	/**
	 * Start the document of one transfer, with its creation event dated now.
	 *
	 * @param string $transferId The transfer identifier.
	 */
	public function __construct(string $transferId) {
		$this->dom = new DOMDocument('1.0', 'UTF-8');
		$this->dom->formatOutput = true;

		$this->premis = $this->element(name: 'premis:premis');
		$this->premis->setAttribute('version', '2.0');
		$this->dom->appendChild($this->premis);

		$event = $this->element(name: 'premis:event');
		$this->premis->appendChild($event);
		$event->appendChild(
			$this->identifier(name: 'premis:eventIdentifier', prefix: 'premis:eventIdentifier', type: 'UUID', value: $transferId)
		);
		$event->appendChild($this->element(name: 'premis:eventType', text: 'creation'));
		$event->appendChild($this->element(name: 'premis:eventDateTime', text: (new DateTime())->format('c')));
	}//end __construct()

	/**
	 * Add the file object of one content file.
	 *
	 * @param string               $uuid The object uuid.
	 * @param array<string, mixed> $file The file metadata.
	 *
	 * @return void
	 */
	public function addFile(string $uuid, array $file): void {
		$premisObject = $this->element(name: 'premis:object');
		$premisObject->setAttributeNS(self::XSI_NAMESPACE, 'xsi:type', 'premis:file');
		$this->premis->appendChild($premisObject);

		$premisObject->appendChild(
			$this->identifier(
				name: 'premis:objectIdentifier',
				prefix: 'premis:objectIdentifier',
				type: 'filepath',
				value: SipLayout::contentPath(uuid: $uuid, file: $file)
			)
		);
		$premisObject->appendChild($this->characteristics(file: $file));
	}//end addFile()

	/**
	 * The XML.
	 *
	 * @return string The document.
	 */
	public function xml(): string {
		return (string)$this->dom->saveXML();
	}//end xml()

	/**
	 * An identifier element with its type and value children.
	 *
	 * @param string $name   The element name.
	 * @param string $prefix The children's name prefix (`...Type`, `...Value`).
	 * @param string $type   The identifier type.
	 * @param string $value  The identifier value.
	 *
	 * @return DOMElement The element.
	 */
	private function identifier(string $name, string $prefix, string $type, string $value): DOMElement {
		$identifier = $this->element(name: $name);
		$identifier->appendChild($this->element(name: $prefix.'Type', text: $type));
		$identifier->appendChild($this->element(name: $prefix.'Value', text: $value));

		return $identifier;
	}//end identifier()

	/**
	 * The objectCharacteristics of a file: fixity, size and format.
	 *
	 * @param array<string, mixed> $file The file metadata.
	 *
	 * @return DOMElement The element.
	 */
	private function characteristics(array $file): DOMElement {
		$fixity = $this->element(name: 'premis:fixity');
		$fixity->appendChild($this->element(name: 'premis:messageDigestAlgorithm', text: 'SHA-256'));
		$fixity->appendChild($this->element(name: 'premis:messageDigest', text: $file['checksum']));

		$designation = $this->element(name: 'premis:formatDesignation');
		$designation->appendChild($this->element(name: 'premis:formatName', text: $file['format']));
		$format = $this->element(name: 'premis:format');
		$format->appendChild($designation);

		$characteristics = $this->element(name: 'premis:objectCharacteristics');
		$characteristics->appendChild($fixity);
		$characteristics->appendChild($this->element(name: 'premis:size', text: (string)$file['size']));
		$characteristics->appendChild($format);

		return $characteristics;
	}//end characteristics()

	/**
	 * A PREMIS element, with text when given.
	 *
	 * @param string      $name The qualified name.
	 * @param string|null $text The text content.
	 *
	 * @return DOMElement The element.
	 */
	private function element(string $name, ?string $text = null): DOMElement {
		$element = $this->dom->createElementNS(self::PREMIS_NAMESPACE, $name);
		if ($text !== null) {
			$element->textContent = $text;
		}

		return $element;
	}//end element()
}//end class
