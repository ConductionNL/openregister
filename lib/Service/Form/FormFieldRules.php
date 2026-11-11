<?php

/**
 * What a form field can produce, and whether that fits a destination property.
 *
 * Pure rules, no lookups: the validator asks these questions per mapped field
 * and turns each "no" into a named finding. Kept apart from the validator so
 * the compatibility table can be read (and tested) on one screen.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * The field-to-property compatibility rules.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */
class FormFieldRules {

	/**
	 * The JSON type each known field type produces.
	 *
	 * A field type not in this table is not judged on type: an unknown widget
	 * is the author's extension, and a guess would be a false finding.
	 *
	 * @var array<string, string>
	 */
	public const PRODUCES = [
		'text' => 'string',
		'string' => 'string',
		'textarea' => 'string',
		'email' => 'string',
		'url' => 'string',
		'tel' => 'string',
		'date' => 'string',
		'datetime' => 'string',
		'time' => 'string',
		'choice' => 'string',
		'radio' => 'string',
		'select' => 'string',
		'reference' => 'string',
		'number' => 'number',
		'integer' => 'integer',
		'checkbox' => 'boolean',
		'boolean' => 'boolean',
		'multichoice' => 'array',
		'array' => 'array',
		'files' => 'array',
		'object' => 'object',
		'file' => 'file',
	];

	/**
	 * The format a field type produces when it names none itself.
	 *
	 * @var array<string, string>
	 */
	public const TYPE_FORMATS = [
		'email' => 'email',
		'url' => 'uri',
		'date' => 'date',
		'datetime' => 'date-time',
		'time' => 'time',
		'reference' => 'uuid',
	];

	/**
	 * Spellings of one format that mean the same thing.
	 *
	 * @var array<string, string>
	 */
	private const FORMAT_ALIASES = [
		'datetime' => 'date-time',
		'url' => 'uri',
	];

	/**
	 * The bounds compared, with the direction in which a field bound is looser.
	 *
	 * An upper bound (1) is looser when the field's is above the property's;
	 * a lower bound (-1) when it is below. An absent field bound is looser
	 * either way. Upper bounds come first, which is the order findings name them.
	 *
	 * @var array<string, int>
	 */
	private const BOUND_DIRECTIONS = [
		'maxLength' => 1,
		'maximum' => 1,
		'maxItems' => 1,
		'minLength' => -1,
		'minimum' => -1,
		'minItems' => -1,
	];

	/**
	 * The JSON type of each PHP type a fixed value can have, by get_debug_type().
	 *
	 * Anything else is an object, or an array when it is a list.
	 *
	 * @var array<string, string>
	 */
	private const JSON_TYPES = [
		'null' => 'null',
		'bool' => 'boolean',
		'int' => 'integer',
		'float' => 'number',
		'string' => 'string',
	];

	/**
	 * Whether the field's type can produce a value of the property's type.
	 *
	 * @param array<string, mixed> $field    The mapping entry.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when it can, or when either side is not judged.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function typeFits(array $field, array $property): bool {
		$produces = (self::PRODUCES[strtolower((string)($field['type'] ?? ''))] ?? null);
		$accepts = $this->acceptedTypes(property: $property);
		if ($produces === null || $accepts === []) {
			return true;
		}

		return $this->acceptsType(type: $produces, accepts: $accepts);
	}//end typeFits()

	/**
	 * The format a field produces: its own `format`, else the one its type implies.
	 *
	 * @param array<string, mixed> $field The mapping entry.
	 *
	 * @return string|null The format, or null for a plain value.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function effectiveFormat(array $field): ?string {
		$explicit = trim((string)($field['format'] ?? ''));
		if ($explicit !== '') {
			return $explicit;
		}

		return (self::TYPE_FORMATS[strtolower((string)($field['type'] ?? ''))] ?? null);
	}//end effectiveFormat()

	/**
	 * Whether the field produces the format the property demands.
	 *
	 * @param array<string, mixed> $field    The mapping entry.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when the property demands none or the field produces it.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function formatFits(array $field, array $property): bool {
		$demanded = trim((string)($property['format'] ?? ''));
		if ($demanded === '') {
			return true;
		}

		$produced = $this->effectiveFormat(field: $field);
		if ($produced === null) {
			return false;
		}

		return $this->canonicalFormat(format: $produced) === $this->canonicalFormat(format: $demanded);
	}//end formatFits()

	/**
	 * The enum a property restricts its value to, or that of its items for an array.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<int, mixed>|null The allowed values, or null when unrestricted.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function enumOf(array $property): ?array {
		if (is_array($property['enum'] ?? null) === true) {
			return array_values($property['enum']);
		}

		$items = ($property['items'] ?? null);
		if (is_array($items) === true && is_array($items['enum'] ?? null) === true) {
			return array_values($items['enum']);
		}

		return null;
	}//end enumOf()

	/**
	 * The values a choice field offers, read from plain values or `{value, label}` objects.
	 *
	 * @param array<string, mixed> $field The mapping entry.
	 *
	 * @return array<int, mixed>|null The offered values, or null for a free field.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function optionsOf(array $field): ?array {
		$options = ($field['options'] ?? null);
		if (is_array($options) === false) {
			return null;
		}

		return array_map(fn (mixed $option): mixed => $this->optionValue(option: $option), array_values($options));
	}//end optionsOf()

	/**
	 * The value one option offers: a plain value, or the `value` of a `{value, label}` object.
	 *
	 * @param mixed $option The option.
	 *
	 * @return mixed The offered value.
	 */
	private function optionValue(mixed $option): mixed {
		if (is_array($option) === true) {
			return ($option['value'] ?? null);
		}

		return $option;
	}//end optionValue()

	/**
	 * The constraints on which the field is looser than the property.
	 *
	 * A bound the property sets and the field leaves open is looser: a
	 * 500-character field into a 100-character property lets the resident type
	 * what the save will refuse. A pattern cannot be compared with another, so
	 * only a missing one counts.
	 *
	 * @param array<string, mixed> $field    The mapping entry.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<int, string> The constraint names, in a stable order.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function looserConstraints(array $field, array $property): array {
		$looser = [];
		foreach (self::BOUND_DIRECTIONS as $bound => $direction) {
			if ($this->isLooser(bounds: [($field[$bound] ?? null), ($property[$bound] ?? null)], direction: $direction) === true) {
				$looser[] = $bound;
			}
		}

		$pattern = (string)($property['pattern'] ?? '');
		if ($pattern !== '' && trim((string)($field['pattern'] ?? '')) === '') {
			$looser[] = 'pattern';
		}

		return $looser;
	}//end looserConstraints()

	/**
	 * Whether a fixed value is one the property accepts, by JSON type and enum.
	 *
	 * @param mixed                $value    The fixed value.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when the property accepts it.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
	 */
	public function fixedValueFits(mixed $value, array $property): bool {
		if ($this->isOutsideEnum(value: $value, property: $property) === true) {
			return false;
		}

		$accepts = $this->acceptedTypes(property: $property);
		if ($accepts === []) {
			return true;
		}

		return $this->acceptsType(type: $this->jsonTypeOf(value: $value), accepts: $accepts);
	}//end fixedValueFits()

	/**
	 * Whether a scalar fixed value falls outside the property's enum.
	 *
	 * A list value is not judged against the enum here.
	 *
	 * @param mixed                $value    The fixed value.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return bool True when the property has an enum and the value is not in it.
	 */
	private function isOutsideEnum(mixed $value, array $property): bool {
		$enum = $this->enumOf(property: $property);

		return $enum !== null && is_array($value) === false && in_array($value, $enum, true) === false;
	}//end isOutsideEnum()

	/**
	 * Whether a JSON type is one the property accepts.
	 *
	 * An integer is a number; the reverse is the 1.5 an integer refuses.
	 *
	 * @param string             $type    The JSON type produced.
	 * @param array<int, string> $accepts The JSON types the property accepts.
	 *
	 * @return bool True when accepted.
	 */
	private function acceptsType(string $type, array $accepts): bool {
		return in_array($type, $accepts, true) === true
			|| ($type === 'integer' && in_array('number', $accepts, true) === true);
	}//end acceptsType()

	/**
	 * The JSON types a property accepts, from a string or a list `type`.
	 *
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<int, string> The accepted types; empty when untyped.
	 */
	private function acceptedTypes(array $property): array {
		$type = ($property['type'] ?? null);
		if (is_string($type) === true && $type !== '') {
			return [$type];
		}

		if (is_array($type) === true) {
			return array_values(array_filter($type, 'is_string'));
		}

		return [];
	}//end acceptedTypes()

	/**
	 * Whether one bound of the field is looser than the property's.
	 *
	 * Both sides are multiplied by the direction, so one comparison serves an
	 * upper bound (1) and a lower bound (-1).
	 *
	 * @param array{0: mixed, 1: mixed} $bounds    The field's bound and the property's bound.
	 * @param int                       $direction 1 for an upper bound, -1 for a lower bound.
	 *
	 * @return bool True when looser.
	 */
	private function isLooser(array $bounds, int $direction): bool {
		[$fieldBound, $propertyBound] = $bounds;
		if (is_numeric($propertyBound) === false) {
			return false;
		}

		if (is_numeric($fieldBound) === false) {
			return true;
		}

		return ($direction * (float)$fieldBound) > ($direction * (float)$propertyBound);
	}//end isLooser()

	/**
	 * The JSON type of a PHP value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The JSON type name.
	 */
	private function jsonTypeOf(mixed $value): string {
		$type = (self::JSON_TYPES[get_debug_type($value)] ?? 'object');
		if (is_array($value) === true && array_is_list($value) === true) {
			return 'array';
		}

		return $type;
	}//end jsonTypeOf()

	/**
	 * One spelling per format.
	 *
	 * @param string $format The format.
	 *
	 * @return string The canonical spelling.
	 */
	private function canonicalFormat(string $format): string {
		$format = strtolower(trim($format));

		return (self::FORMAT_ALIASES[$format] ?? $format);
	}//end canonicalFormat()
}//end class
