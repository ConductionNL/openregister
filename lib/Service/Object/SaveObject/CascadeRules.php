<?php

/**
 * OpenRegister CascadeRules
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectHandling;

/**
 * What a schema property's definition says about cascading its nested objects.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class CascadeRules {

	/**
	 * Whether a property cascades as a single nested object.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True for an object with a `$ref` and inversedBy or cascade handling.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function isObjectProperty(array $property): bool {
		if (self::writesBack(definition: $property) === true) {
			return false;
		}

		return $property['type'] === 'object'
			&& ($property['$ref'] ?? null) !== null
			&& (isset($property['inversedBy']) === true
			|| ($property['objectConfiguration']['handling'] ?? null) === 'cascade');
	}//end isObjectProperty()

	/**
	 * Whether a property cascades as an array of nested objects.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True for an array with a `$ref` and inversedBy, cascade or related handling.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function isArrayProperty(array $property): bool {
		if (self::anyWritesBack(property: $property) === true) {
			return false;
		}

		return $property['type'] === 'array'
			&& self::declares(property: $property, key: '$ref')
			&& self::linksItems(property: $property);
	}//end isArrayProperty()

	/**
	 * Note how an array property is handled, and which references it held before this save.
	 *
	 * @param CascadeRun $run The cascade, on one property.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function classifyArray(CascadeRun $run): void {
		$run->isRelated = self::relates(property: $run->definition);

		$run->oldUuids = [];
		$stored = $run->stored[$run->property] ?? null;
		$tracked = $run->isRelated === true || self::cascades(property: $run->definition) === true;
		if ($tracked === true && is_array($stored) === true) {
			$run->oldUuids = array_values(array_filter($stored, 'is_string'));
		}
	}//end classifyArray()

	/**
	 * Put the outcome of a single cascade into the data.
	 *
	 * Without inversedBy, or with write-back on, the property holds the created UUID.
	 * Otherwise it leaves the data, unless writeBack holds some other value.
	 *
	 * @param CascadeRun  $run         The cascade, on one property.
	 * @param string|null $createdUuid The created object's UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function applySingleResult(CascadeRun $run, ?string $createdUuid): void {
		$definition = $run->definition;
		if (($definition['inversedBy'] ?? null) === null || self::writesBack(definition: $definition) === true) {
			$run->data[$run->property] = $createdUuid;
			return;
		}

		if (($definition['writeBack'] ?? false) === false) {
			unset($run->data[$run->property]);
		}
	}//end applySingleResult()

	/**
	 * Put the outcome of an array cascade into the data.
	 *
	 * Without inversedBy, or with write-back on, the property holds the created UUIDs.
	 * Otherwise it leaves the data.
	 *
	 * @param CascadeRun $run          The cascade, on one property.
	 * @param array      $createdUuids The created objects' UUIDs.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function applyArrayResult(CascadeRun $run, array $createdUuids): void {
		$definition = $run->definition;
		if (self::declares(property: $definition, key: 'inversedBy') === false
			|| self::anyWritesBack(property: $definition) === true
		) {
			$run->data[$run->property] = $createdUuids;
			return;
		}

		unset($run->data[$run->property]);
	}//end applyArrayResult()

	/**
	 * The definition of an array property's items, with the keys set on the array itself.
	 *
	 * @param array $property The property definition.
	 *
	 * @return array|null The items definition, or null when it has no `$ref`.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function itemsDefinition(array $property): ?array {
		foreach (['$ref', 'inversedBy', 'register', 'objectConfiguration'] as $key) {
			if (($property[$key] ?? null) !== null) {
				$property['items'][$key] = $property[$key];
			}
		}

		if (isset($property['items']['$ref']) === false) {
			return null;
		}

		return $property['items'];
	}//end itemsDefinition()

	/**
	 * Whether a definition turns write-back on.
	 *
	 * @param mixed $definition The property or items definition.
	 *
	 * @return bool True when writeBack is true.
	 */
	private static function writesBack(mixed $definition): bool {
		return ($definition['writeBack'] ?? null) === true;
	}//end writesBack()

	/**
	 * Whether a key is set on a property or on its items.
	 *
	 * @param array  $property The property definition.
	 * @param string $key      The key.
	 *
	 * @return bool True when set.
	 */
	private static function declares(array $property, string $key): bool {
		return ($property[$key] ?? $property['items'][$key] ?? null) !== null;
	}//end declares()

	/**
	 * Whether a property ties its items to the parent: by inversedBy, cascade or related handling.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True when it does.
	 */
	private static function linksItems(array $property): bool {
		return self::declares(property: $property, key: 'inversedBy')
			|| self::cascades(property: $property)
			|| self::relates(property: $property);
	}//end linksItems()

	/**
	 * Whether a property or its items turn write-back on.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True when either does.
	 */
	private static function anyWritesBack(array $property): bool {
		return self::writesBack(definition: $property) === true
			|| self::writesBack(definition: ($property['items'] ?? [])) === true;
	}//end anyWritesBack()

	/**
	 * Whether a property or its items are handled as cascade.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True for such handling.
	 */
	private static function cascades(array $property): bool {
		return ($property['objectConfiguration']['handling'] ?? null) === 'cascade'
			|| ($property['items']['objectConfiguration']['handling'] ?? null) === 'cascade';
	}//end cascades()

	/**
	 * Whether a property or its items are handled as related objects.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True for such handling.
	 */
	private static function relates(array $property): bool {
		return in_array(($property['objectConfiguration']['handling'] ?? null), ObjectHandling::RELATING, true) === true
			|| in_array(($property['items']['objectConfiguration']['handling'] ?? null), ObjectHandling::RELATING, true) === true;
	}//end relates()
}//end class
