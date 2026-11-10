<?php

/**
 * SchemaPropertyDocument: a schema's properties as JSON Schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema as SchemaEntity;
use stdClass;

/**
 * Renders the `properties` of a schema's JSON Schema document.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class SchemaPropertyDocument {

	/**
	 * The properties as an object keyed by property name.
	 *
	 * @param array<mixed> $properties The schema's properties.
	 *
	 * @return stdClass
	 */
	public function render(array $properties): stdClass {
		$document = new stdClass();
		foreach ($properties as $name => $property) {
			if (($property['properties'] ?? null) !== null) {
				$document->{$name} = self::nestedObject(property: $property);
				continue;
			}

			$document->{$name} = self::flatProperty(property: $property);
		}

		return $document;
	}//end render()

	/**
	 * A property holding nested properties, with the nested required list.
	 *
	 * @param array<mixed> $property The property.
	 *
	 * @return stdClass
	 */
	private static function nestedObject(array $property): stdClass {
		$nested = new stdClass();
		$nested->type = 'object';
		$nested->title = $property['title'];
		$nested->required = [];
		$nested->properties = new stdClass();

		foreach (self::entries(value: $property['properties']) as $name => $subProperty) {
			if (($subProperty['required'] ?? null) === true) {
				$nested->required[] = $name;
			}

			$nested->properties->{$name} = self::subProperty(subProperty: $subProperty);
		}

		return $nested;
	}//end nestedObject()

	/**
	 * A nested property: its keys as declared, without an empty `oneOf`.
	 *
	 * @param mixed $subProperty The nested property.
	 *
	 * @return stdClass
	 */
	private static function subProperty(mixed $subProperty): stdClass {
		$rendered = new stdClass();
		foreach (self::entries(value: $subProperty) as $key => $value) {
			if ($key !== 'oneOf' || empty($value) === false) {
				$rendered->{$key} = $value;
			}
		}

		return $rendered;
	}//end subProperty()

	/**
	 * A plain property: its filled keys, read-only when it is computed.
	 *
	 * @param mixed $property The property.
	 *
	 * @return stdClass
	 */
	private static function flatProperty(mixed $property): stdClass {
		$rendered = new stdClass();
		foreach (self::entries(value: $property) as $key => $value) {
			if ($key !== 'required' && self::isFilled(key: (string) $key, value: $value) === true) {
				$rendered->{$key} = $value;
			}
		}

		if (self::isComputed(property: $property) === true) {
			$rendered->readOnly = true;
		}

		return $rendered;
	}//end flatProperty()

	/**
	 * Whether a property is computed. Both engines count: the Twig `computed`
	 * marker and the JSON-AST `calculation` key a property form forwards.
	 *
	 * @param mixed $property The property.
	 *
	 * @return bool
	 */
	private static function isComputed(mixed $property): bool {
		return is_array($property['computed'] ?? null) === true || is_array($property['calculation'] ?? null) === true;
	}//end isComputed()

	/**
	 * The entries of a declared value; a value that is no map has none.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private static function entries(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end entries()

	/**
	 * Whether a key is worth rendering. A value-carrying key keeps its falsy
	 * value; '' is not one of them, being what the property form ships for a
	 * default nobody filled in.
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 *
	 * @return bool
	 */
	private static function isFilled(string $key, mixed $value): bool {
		$carriesValue = (in_array($key, SchemaEntity::VALUE_CARRYING_PROPERTY_KEYS, true) === true && $value !== '');

		return $carriesValue === true || empty($value) === false;
	}//end isFilled()
}//end class
