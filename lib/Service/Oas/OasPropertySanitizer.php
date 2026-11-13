<?php

/**
 * Reduces a schema property definition to what an OpenAPI 3.1 document may carry.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Sanitises property definitions for the generated OpenAPI document.
 *
 * @spec openspec/specs/oas-generation/spec.md
 */
class OasPropertySanitizer {

	/**
	 * OpenAPI schema keywords a property definition keeps, in output order.
	 *
	 * @var list<string>
	 */
	private const PROPERTY_KEYWORDS = [
		'type',
		'format',
		'description',
		'example',
		'examples',
		'default',
		'enum',
		'const',
		'multipleOf',
		'maximum',
		'exclusiveMaximum',
		'minimum',
		'exclusiveMinimum',
		'maxLength',
		'minLength',
		'pattern',
		'maxItems',
		'minItems',
		'uniqueItems',
		'maxProperties',
		'minProperties',
		'required',
		'properties',
		'items',
		'additionalProperties',
		'allOf',
		'anyOf',
		'oneOf',
		'not',
		'$ref',
		'nullable',
		'readOnly',
		'writeOnly',
		'title',
	];

	/**
	 * The types a definition may declare.
	 */
	private const PROPERTY_TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

	/**
	 * OpenAPI type per shorthand type name.
	 */
	private const SHORTHAND_TYPES = [
		'int'    => 'integer',
		'float'  => 'number',
		'bool'   => 'boolean',
		'string' => 'string',
		'array'  => 'array',
		'object' => 'object',
	];

	/**
	 * The items schema when none is usable.
	 */
	private const STRING_ITEMS = ['type' => 'string'];

	/**
	 * Spells a bare $ref as a component name; the document's generator shares it.
	 *
	 * @var OasNames
	 */
	public readonly OasNames $names;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->names = new OasNames();
	}//end __construct()

	/**
	 * A valid OpenAPI definition for a property.
	 *
	 * Internal keys such as objectConfiguration, inversedBy and authorization go.
	 * A `calendar-range` or `image-url` property becomes a reference to its shared
	 * component ({@see OasExtendedTypes}).
	 *
	 * @param mixed $definition The property definition
	 *
	 * @return array<string, mixed> The definition
	 *
	 * @spec openspec/specs/oas-generation/spec.md
	 */
	public function sanitize(mixed $definition): array {
		if (is_array($definition) === false) {
			return ['type' => 'string', 'description' => 'Property value'];
		}

		// A structured extended type is described once, as a shared component.
		$reference = OasExtendedTypes::referenceFor(definition: $definition);
		if ($reference !== null) {
			return $reference;
		}

		$clean = $this->nested(clean: $this->keywordsOf(definition: $definition));
		$clean = $this->normaliseType(clean: $this->withoutInvalidKeywords(clean: $clean));
		if (isset($clean['$ref']) === false) {
			$clean['type'] ??= 'string';
			$clean['description'] ??= 'Property value';
		}

		return $clean;
	}//end sanitize()

	/**
	 * The OpenAPI type of a property definition or shorthand type name.
	 *
	 * @param mixed $definition The property definition
	 *
	 * @return string The type; 'string' when it cannot be determined
	 *
	 * @spec openspec/specs/mock-registers/spec.md#requirement-schema-compliance-with-adr-006
	 */
	public function typeOf(mixed $definition): string {
		if (is_string($definition) === true) {
			return (self::SHORTHAND_TYPES[$definition] ?? 'string');
		}

		if (is_array($definition) === true && in_array($definition['type'] ?? null, self::PROPERTY_TYPES, true) === true) {
			return $definition['type'];
		}

		return 'string';
	}//end typeOf()

	/**
	 * The filled OpenAPI keywords of a definition, in output order.
	 *
	 * @param array<string, mixed> $definition The property definition
	 *
	 * @return array<string, mixed> The keywords
	 */
	private function keywordsOf(array $definition): array {
		$clean = [];
		foreach (self::PROPERTY_KEYWORDS as $keyword) {
			if (($definition[$keyword] ?? null) !== null) {
				$clean[$keyword] = $definition[$keyword];
			}
		}

		return $clean;
	}//end keywordsOf()

	/**
	 * Sanitise the nested definitions: items, properties and the composition keywords.
	 *
	 * @param array<string, mixed> $clean The definition
	 *
	 * @return array<string, mixed> The definition
	 */
	private function nested(array $clean): array {
		if (is_array($clean['items'] ?? null) === true) {
			$clean['items'] = $this->sanitize(definition: $clean['items']);
		}

		if (is_array($clean['properties'] ?? null) === true) {
			$clean['properties'] = array_map(fn (mixed $sub): array => $this->sanitize(definition: $sub), $clean['properties']);
		}

		foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
			if (is_array($clean[$keyword] ?? null) === true) {
				$clean[$keyword] = array_map(fn (mixed $item): mixed => $this->compositionItem(item: $item), $clean[$keyword]);
			}
		}

		return $clean;
	}//end nested()

	/**
	 * Sanitise one item of a composition keyword; anything but an object stays.
	 *
	 * @param mixed $item The item
	 *
	 * @return mixed The item
	 */
	private function compositionItem(mixed $item): mixed {
		if (is_array($item) === true) {
			return $this->sanitize(definition: $item);
		}

		return $item;
	}//end compositionItem()

	/**
	 * Remove keyword values that violate the OpenAPI spec.
	 *
	 * @param array<string, mixed> $clean The definition
	 *
	 * @return array<string, mixed> The definition
	 */
	private function withoutInvalidKeywords(array $clean): array {
		$clean = $this->withoutEmptyLists(clean: $clean);
		$clean = $this->usableAllOf(clean: $clean);
		$clean = $this->normaliseRef(clean: $clean);

		// A boolean `required` leaks from schema config; only a list is valid here.
		if (isset($clean['required']) === true && is_array($clean['required']) === false) {
			unset($clean['required']);
		}

		return $clean;
	}//end withoutInvalidKeywords()

	/**
	 * Drop oneOf, anyOf and enum unless they are non-empty lists.
	 *
	 * @param array<string, mixed> $clean The definition
	 *
	 * @return array<string, mixed> The definition
	 */
	private function withoutEmptyLists(array $clean): array {
		foreach (['oneOf', 'anyOf', 'enum'] as $keyword) {
			if (isset($clean[$keyword]) === true
				&& (empty($clean[$keyword]) === true || is_array($clean[$keyword]) === false)
			) {
				unset($clean[$keyword]);
			}
		}

		return $clean;
	}//end withoutEmptyLists()

	/**
	 * Keep only the object items of allOf, and drop allOf when none remain.
	 *
	 * @param array<string, mixed> $clean The definition
	 *
	 * @return array<string, mixed> The definition
	 */
	private function usableAllOf(array $clean): array {
		if (isset($clean['allOf']) === false) {
			return $clean;
		}

		$items = $this->objectItems(allOf: $clean['allOf']);
		unset($clean['allOf']);
		if ($items !== []) {
			$clean['allOf'] = $items;
		}

		return $clean;
	}//end usableAllOf()

	/**
	 * The object items of an allOf value; sanitising has already filled them.
	 *
	 * @param mixed $allOf The allOf value
	 *
	 * @return list<array<string, mixed>> The items
	 */
	private function objectItems(mixed $allOf): array {
		if (is_array($allOf) === false) {
			return [];
		}

		return array_values(array_filter($allOf, 'is_array'));
	}//end objectItems()

	/**
	 * Drop a $ref that is not a non-empty string and turn a bare one into a component reference.
	 *
	 * @param array<string, mixed> $clean The definition
	 *
	 * @return array<string, mixed> The definition
	 */
	private function normaliseRef(array $clean): array {
		if (isset($clean['$ref']) === false) {
			return $clean;
		}

		if (empty($clean['$ref']) === true || is_string($clean['$ref']) === false) {
			unset($clean['$ref']);
			return $clean;
		}

		if (strpos($clean['$ref'], '#/') !== 0) {
			$clean['$ref'] = '#/components/schemas/' . $this->names->schemaName(title: $clean['$ref']);
		}

		return $clean;
	}//end normaliseRef()

	/**
	 * Map an unknown `type` to string and give every array and every `items` a valid items object.
	 *
	 * @param array<string, mixed> $clean The definition
	 *
	 * @return array<string, mixed> The definition
	 */
	private function normaliseType(array $clean): array {
		if (is_string($clean['type'] ?? null) === true
			&& in_array($clean['type'], self::PROPERTY_TYPES, true) === false
		) {
			$clean['type'] = 'string';
		}

		if (isset($clean['items']) === true) {
			$clean['items'] = $this->itemsObject(items: $clean['items']);
		}

		if (($clean['type'] ?? null) === 'array') {
			$clean['items'] ??= self::STRING_ITEMS;
		}

		return $clean;
	}//end normaliseType()

	/**
	 * Make `items` a JSON Schema object; an array has been sanitised into one already.
	 *
	 * @param mixed $items The items value
	 *
	 * @return array<string, mixed> The items object
	 */
	private function itemsObject(mixed $items): array {
		if (is_array($items) === true) {
			return $items;
		}

		return self::STRING_ITEMS;
	}//end itemsObject()
}//end class
