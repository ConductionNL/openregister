<?php

/**
 * InversedByNormaliser: the declared inversedBy of a schema's properties.
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

/**
 * Brings `inversedBy` on a property, and on its `items`, to its stored form.
 *
 * A string or a list of strings stays as it is. The legacy object form
 * `{"id": "fieldName"}` becomes the string. Anything else is removed.
 *
 * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class InversedByNormaliser {

	/**
	 * The properties with every `inversedBy` normalised.
	 *
	 * @param array<mixed> $properties The schema's properties.
	 *
	 * @return array<mixed>
	 */
	public function normalised(array $properties): array {
		foreach ($properties as $name => $property) {
			if (is_array($property) === true) {
				$properties[$name] = self::normalisedProperty(property: $property);
			}
		}

		return $properties;
	}//end normalised()

	/**
	 * One property; an object or list form on the property settles it, leaving its items as they are.
	 *
	 * @param array<mixed> $property The property.
	 *
	 * @return array<mixed>
	 */
	private static function normalisedProperty(array $property): array {
		$normalised = self::normalisedBlock(block: $property);
		$settled = is_array($property['inversedBy'] ?? null) === true && self::isKept(inversedBy: $property['inversedBy']) === true;
		if ($settled === false && is_array($property['items'] ?? null) === true) {
			$normalised['items'] = self::normalisedBlock(block: $property['items']);
		}

		return $normalised;
	}//end normalisedProperty()

	/**
	 * One block carrying `inversedBy`.
	 *
	 * @param array<mixed> $block The property or its items.
	 *
	 * @return array<mixed>
	 */
	private static function normalisedBlock(array $block): array {
		$inversedBy = ($block['inversedBy'] ?? null);
		if (is_array($inversedBy) === true && ($inversedBy['id'] ?? null) !== null) {
			$block['inversedBy'] = $inversedBy['id'];
			return $block;
		}

		if (self::isRemoved(inversedBy: $inversedBy) === true) {
			unset($block['inversedBy']);
		}

		return $block;
	}//end normalisedBlock()

	/**
	 * Whether a value is neither absent, a string, nor a valid object or list form.
	 *
	 * @param mixed $inversedBy The value.
	 *
	 * @return bool
	 */
	private static function isRemoved(mixed $inversedBy): bool {
		return $inversedBy !== null && is_string($inversedBy) === false && self::isKept(inversedBy: $inversedBy) === false;
	}//end isRemoved()

	/**
	 * Whether a non-string `inversedBy` is a valid object or list form.
	 *
	 * @param mixed $inversedBy The value.
	 *
	 * @return bool
	 */
	private static function isKept(mixed $inversedBy): bool {
		return is_array($inversedBy) === true
			&& (($inversedBy['id'] ?? null) !== null || array_is_list($inversedBy) === true);
	}//end isKept()
}//end class
