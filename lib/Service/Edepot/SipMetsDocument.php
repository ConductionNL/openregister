<?php

/**
 * OpenRegister SIP METS Document
 *
 * The mets.xml of one SIP package.
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

use DOMDocument;
use DOMElement;

/**
 * Writes mets.xml: a fileSec with ORIGINAL, RENDITION and METADATA groups and
 * a physical structMap with one div per object pointing at its files.
 *
 * The MDTO documents travel in the package too: the object's own and one per
 * file. Leaving them out of fileSec made mets.xml describe less than the
 * package contains.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
 */
final class SipMetsDocument {

	/**
	 * METS namespace URI.
	 *
	 * @var string
	 */
	private const METS_NAMESPACE = 'http://www.loc.gov/METS/';

	/**
	 * XLink namespace URI.
	 *
	 * @var string
	 */
	private const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

	/**
	 * The document.
	 *
	 * @var DOMDocument
	 */
	private DOMDocument $dom;

	/**
	 * The file groups by use: original, rendition, metadata.
	 *
	 * @var array<string, DOMElement>
	 */
	private array $groups = [];

	/**
	 * The structMap's root div.
	 *
	 * @var DOMElement
	 */
	private DOMElement $rootDiv;

	/**
	 * The next file number, shared by content and metadata files.
	 *
	 * @var int
	 */
	private int $fileCounter = 1;

	/**
	 * Start the document of one transfer.
	 *
	 * @param string $transferId The transfer identifier.
	 */
	public function __construct(string $transferId) {
		$this->dom = new DOMDocument('1.0', 'UTF-8');
		$this->dom->formatOutput = true;

		$mets = $this->element(name: 'mets:mets', attributes: ['OBJID' => $transferId, 'TYPE' => 'SIP']);
		$this->dom->appendChild($mets);

		$mets->appendChild($this->fileSection());

		$structMap = $this->element(name: 'mets:structMap', attributes: ['TYPE' => 'physical']);
		$mets->appendChild($structMap);
		$this->rootDiv = $this->element(name: 'mets:div', attributes: ['LABEL' => 'SIP-'.$transferId]);
		$structMap->appendChild($this->rootDiv);
	}//end __construct()

	/**
	 * Add one object: its div, its content files, then its MDTO documents.
	 *
	 * @param string                           $uuid     The object uuid.
	 * @param array<int, array<string, mixed>> $files    The object's content files.
	 * @param array<int, array<string, mixed>> $metadata The object's MDTO documents (manifest rows).
	 *
	 * @return void
	 */
	public function addObject(string $uuid, array $files, array $metadata): void {
		$objectDiv = $this->element(name: 'mets:div', attributes: ['LABEL' => $uuid, 'TYPE' => 'object']);
		$this->rootDiv->appendChild($objectDiv);

		$this->addContentFiles(objectDiv: $objectDiv, uuid: $uuid, files: $files);

		foreach ($metadata as $row) {
			$row['mime'] = 'application/xml';
			$row['href'] = $row['path'];
			$this->addFile(group: $this->groups['metadata'], objectDiv: $objectDiv, idPrefix: 'MD-', file: $row);
		}
	}//end addObject()

	/**
	 * Add an object's content files to the ORIGINAL or RENDITION group.
	 *
	 * @param DOMElement                       $objectDiv The object's div.
	 * @param string                           $uuid      The object uuid.
	 * @param array<int, array<string, mixed>> $files     The content files.
	 *
	 * @return void
	 */
	private function addContentFiles(DOMElement $objectDiv, string $uuid, array $files): void {
		foreach ($files as $file) {
			$href = SipLayout::contentPath(uuid: $uuid, file: $file);
			$this->addFile(
				group: $this->groups[SipLayout::subDir(file: $file)],
				objectDiv: $objectDiv,
				idPrefix: 'FILE-',
				file: ['size' => $file['size'], 'mime' => $file['format'], 'checksum' => $file['checksum'], 'href' => $href]
			);
		}
	}//end addContentFiles()

	/**
	 * The XML.
	 *
	 * @return string The document.
	 */
	public function xml(): string {
		return (string)$this->dom->saveXML();
	}//end xml()

	/**
	 * Add one file to a group, and a pointer to it to the object's div.
	 *
	 * @param DOMElement           $group     The file group.
	 * @param DOMElement           $objectDiv The object's div.
	 * @param string               $idPrefix  `FILE-` or `MD-`.
	 * @param array<string, mixed> $file      The size, mime, checksum and href.
	 *
	 * @return void
	 */
	private function addFile(DOMElement $group, DOMElement $objectDiv, string $idPrefix, array $file): void {
		$fileId = $idPrefix.$this->fileCounter;
		$this->fileCounter++;

		$element = $this->element(name: 'mets:file', attributes: self::fileAttributes(fileId: $fileId, file: $file));
		$locat = $this->element(name: 'mets:FLocat', attributes: ['LOCTYPE' => 'URL']);
		$locat->setAttributeNS(self::XLINK_NAMESPACE, 'xlink:href', $file['href']);
		$element->appendChild($locat);
		$group->appendChild($element);
		$objectDiv->appendChild($this->element(name: 'mets:fptr', attributes: ['FILEID' => $fileId]));
	}//end addFile()

	/**
	 * The fileSec with its ORIGINAL, RENDITION and METADATA groups.
	 *
	 * @return DOMElement The fileSec.
	 */
	private function fileSection(): DOMElement {
		$fileSec = $this->element(name: 'mets:fileSec');
		foreach (['original' => 'ORIGINAL', 'rendition' => 'RENDITION', 'metadata' => 'METADATA'] as $key => $use) {
			$this->groups[$key] = $this->element(name: 'mets:fileGrp', attributes: ['USE' => $use]);
			$fileSec->appendChild($this->groups[$key]);
		}

		return $fileSec;
	}//end fileSection()

	/**
	 * The attributes of a mets:file, in order.
	 *
	 * @param string               $fileId The file id.
	 * @param array<string, mixed> $file   The size, mime and checksum.
	 *
	 * @return array<string, string> The attributes.
	 */
	private static function fileAttributes(string $fileId, array $file): array {
		return [
			'ID' => $fileId,
			'SIZE' => (string)$file['size'],
			'MIMETYPE' => $file['mime'],
			'CHECKSUM' => $file['checksum'],
			'CHECKSUMTYPE' => 'SHA-256',
		];
	}//end fileAttributes()

	/**
	 * A METS element with attributes.
	 *
	 * @param string                $name       The qualified name.
	 * @param array<string, string> $attributes The attributes, in order.
	 *
	 * @return DOMElement The element.
	 */
	private function element(string $name, array $attributes = []): DOMElement {
		$element = $this->dom->createElementNS(self::METS_NAMESPACE, $name);
		foreach ($attributes as $attribute => $value) {
			$element->setAttribute($attribute, $value);
		}

		return $element;
	}//end element()
}//end class
