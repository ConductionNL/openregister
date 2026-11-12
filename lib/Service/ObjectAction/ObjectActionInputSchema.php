<?php

/**
 * Questions about a declared action's input JSON Schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * Reads the properties of an action's input schema.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
 */
final class ObjectActionInputSchema {

	/**
	 * Whether any declared input property passes a test.
	 *
	 * @param mixed    $inputs The declared input schema.
	 * @param callable $test   fn(array $property): bool.
	 *
	 * @return bool True when one does.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function anyProperty(mixed $inputs, callable $test): bool {
		if (is_array($inputs) === false) {
			return false;
		}

		foreach ((array)($inputs['properties'] ?? []) as $property) {
			if (is_array($property) === true && $test($property) === true) {
				return true;
			}
		}

		return false;
	}//end anyProperty()

	/**
	 * Whether an input property defaults from the object the action runs on.
	 *
	 * @param array<string, mixed> $property The input property.
	 *
	 * @return bool True when its default starts with `@object`.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function defaultsFromObject(array $property): bool {
		$default = ($property['default'] ?? null);

		return (is_string($default) === true && str_starts_with($default, '@object') === true);
	}//end defaultsFromObject()

	/**
	 * Whether an input property is `writeOnly`.
	 *
	 * @param array<string, mixed> $property The input property.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/declared-actions/spec.md#requirement-a-declared-action-may-carry-inputs-a-precondition-surfaces-and-one-body
	 */
	public static function isWriteOnly(array $property): bool {
		return (($property['writeOnly'] ?? false) === true);
	}//end isWriteOnly()
}//end class
