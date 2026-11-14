<?php

/**
 * XmlObjectWriter: objects as an XML document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use DateTimeInterface;
use JsonSerializable;
use XMLWriter;

/**
 * Writes serialised objects as `<objects>` with one `<object>` per record.
 *
 * Each property is a child element named after it. A list becomes repeated
 * children, a nested object nested elements, and null an empty element with
 * `xsi:nil="true"`. A property whose name is not a valid XML name (it starts
 * with a digit, holds a space, or is `@self`) becomes `<property name="...">`,
 * so no data is dropped and the document stays well formed. Characters XML 1.0
 * does not allow are replaced by U+FFFD for the same reason.
 *
 * Built with XMLWriter in memory, so the rows are not held twice as a DOM.
 * XMLWriter escapes every value, and no doctype or entity is written.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
 */
final class XmlObjectWriter {

	/**
	 * The XML Schema instance namespace, for `xsi:nil`.
	 */
	public const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

	/**
	 * The element a property with an invalid XML name is written as.
	 */
	public const FALLBACK_ELEMENT = 'property';

	/**
	 * The element an entry of a list inside a list is written as.
	 */
	public const LIST_ITEM_ELEMENT = 'item';

	/**
	 * Write the objects as one XML document.
	 *
	 * @param array<int, array<string, mixed>> $rows The objects, as `jsonSerialize()` returns them.
	 *
	 * @return string The document, UTF-8.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
	 */
	public function write(array $rows): string {
		$xml = new XMLWriter();
		$xml->openMemory();
		$xml->setIndent(true);
		$xml->setIndentString('  ');
		$xml->startDocument('1.0', 'UTF-8');
		$xml->startElement('objects');
		$xml->writeAttribute('xmlns:xsi', self::XSI_NAMESPACE);

		foreach ($rows as $row) {
			$this->writeObject(xml: $xml, row: $row);
		}

		$xml->endElement();
		$xml->endDocument();

		return $xml->outputMemory();
	}//end write()

	/**
	 * Whether a property name can be an XML element name as it is.
	 *
	 * Letters, digits, `_`, `-` and `.`, not starting with a digit, `-` or `.`.
	 * A colon is left out, so no name is read as a namespace prefix.
	 *
	 * @param string $name The property name.
	 *
	 * @return bool True when it can be written as an element name.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
	 */
	public static function isElementName(string $name): bool {
		return preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/', $name) === 1;
	}//end isElementName()

	/**
	 * One record as an `<object>` with its id as an attribute.
	 *
	 * @param XMLWriter $xml The writer.
	 * @param mixed     $row The record.
	 *
	 * @return void
	 */
	private function writeObject(XMLWriter $xml, mixed $row): void {
		$row = $this->plain(value: $row);
		$xml->startElement('object');

		if (is_array($row) === true) {
			$id = ($row['@self']['id'] ?? ($row['id'] ?? null));
			if (is_scalar($id) === true) {
				$xml->writeAttribute('id', $this->text(value: (string)$id));
			}

			foreach ($row as $name => $value) {
				$this->writeProperty(xml: $xml, name: (string)$name, value: $value);
			}
		}

		$xml->endElement();
	}//end writeObject()

	/**
	 * One property: repeated elements for a list, one element otherwise.
	 *
	 * @param XMLWriter $xml   The writer.
	 * @param string    $name  The property name.
	 * @param mixed     $value The value.
	 *
	 * @return void
	 */
	private function writeProperty(XMLWriter $xml, string $name, mixed $value): void {
		$value = $this->plain(value: $value);
		if (is_array($value) === true && $value !== [] && array_is_list($value) === true) {
			foreach ($value as $entry) {
				$this->writeElement(xml: $xml, name: $name, value: $entry);
			}

			return;
		}

		$this->writeElement(xml: $xml, name: $name, value: $value);
	}//end writeProperty()

	/**
	 * One element holding one value.
	 *
	 * @param XMLWriter $xml   The writer.
	 * @param string    $name  The element's property name.
	 * @param mixed     $value The value.
	 *
	 * @return void
	 */
	private function writeElement(XMLWriter $xml, string $name, mixed $value): void {
		$this->startNamed(xml: $xml, name: $name);
		$this->writeValue(xml: $xml, value: $this->plain(value: $value));
		$xml->endElement();
	}//end writeElement()

	/**
	 * The content of an element: nil, text, nested elements, or list items.
	 *
	 * @param XMLWriter $xml   The writer.
	 * @param mixed     $value The value, already plain.
	 *
	 * @return void
	 */
	private function writeValue(XMLWriter $xml, mixed $value): void {
		if ($value === null) {
			$xml->writeAttribute('xsi:nil', 'true');
			return;
		}

		if ($value === true) {
			$xml->text('true');
			return;
		}

		if ($value === false) {
			$xml->text('false');
			return;
		}

		if (is_array($value) === false) {
			$xml->text($this->text(value: (string)$value));
			return;
		}

		if ($value !== [] && array_is_list($value) === true) {
			// A list inside a list: its entries cannot repeat the parent's name.
			foreach ($value as $entry) {
				$this->writeElement(xml: $xml, name: self::LIST_ITEM_ELEMENT, value: $entry);
			}

			return;
		}

		foreach ($value as $childName => $childValue) {
			$this->writeProperty(xml: $xml, name: (string)$childName, value: $childValue);
		}
	}//end writeValue()

	/**
	 * Open the element for a property: its own name when valid, else `<property name="...">`.
	 *
	 * @param XMLWriter $xml  The writer.
	 * @param string    $name The property name.
	 *
	 * @return void
	 */
	private function startNamed(XMLWriter $xml, string $name): void {
		if (self::isElementName(name: $name) === true) {
			$xml->startElement($name);
			return;
		}

		$xml->startElement(self::FALLBACK_ELEMENT);
		$xml->writeAttribute('name', $this->text(value: $name));
	}//end startNamed()

	/**
	 * A value as arrays and scalars only.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed Null, a scalar or an array.
	 */
	private function plain(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		if ($value instanceof JsonSerializable) {
			return $this->plain(value: $value->jsonSerialize());
		}

		if (is_object($value) === true) {
			return json_decode((string)json_encode($value), true);
		}

		return $value;
	}//end plain()

	/**
	 * Text safe for XML 1.0: valid UTF-8, without the characters XML does not allow.
	 *
	 * @param string $value The text.
	 *
	 * @return string The text, with each disallowed character replaced by U+FFFD.
	 */
	private function text(string $value): string {
		if (mb_check_encoding($value, 'UTF-8') === false) {
			$value = mb_scrub($value, 'UTF-8');
		}

		return (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $value);
	}//end text()
}//end class
