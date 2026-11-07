<?php

/**
 * OpenRegister MappedField
 *
 * One form field together with the destination property it writes into.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * What every per-field check of {@see FormDestinationValidator} reads.
 *
 * The field and property names are read from the mapping entry once, here,
 * so every finding about the field names them the same way.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
final class MappedField {

	/**
	 * The field name, as the mapping entry gives it.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * The destination property name, as the mapping entry gives it.
	 *
	 * @var string
	 */
	public readonly string $propertyName;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $field    The mapping entry.
	 * @param array<string, mixed> $property The schema property it writes into; empty when the schema has none.
	 */
	public function __construct(
		public readonly array $field,
		public readonly array $property,
	) {
		$this->name = (string)($field['field'] ?? '');
		$this->propertyName = (string)($field['property'] ?? '');
	}//end __construct()

	/**
	 * The field type, for a message.
	 *
	 * @return string The declared field type, or empty.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function fieldType(): string {
		return (string)($this->field['type'] ?? '');
	}//end fieldType()

	/**
	 * The property's type, for a message.
	 *
	 * @return string The type, or types joined with a slash.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function propertyType(): string {
		$type = ($this->property['type'] ?? '');
		if (is_array($type) === true) {
			return implode('/', array_map('strval', $type));
		}

		return (string)$type;
	}//end propertyType()

	/**
	 * The format the property demands, for a message.
	 *
	 * @return string The demanded format, or empty.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function format(): string {
		return (string)($this->property['format'] ?? '');
	}//end format()

	/**
	 * One finding about this field.
	 *
	 * @param string $code    The finding code.
	 * @param string $message The translated message.
	 *
	 * @return array{field: string, property: string, code: string, message: string} The finding, keyed in the order every finding uses.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function finding(string $code, string $message): array {
		return [
			'field' => $this->name,
			'property' => $this->propertyName,
			'code' => $code,
			'message' => $message,
		];
	}//end finding()
}//end class
