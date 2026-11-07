<?php

/**
 * OpenRegister CascadeItem
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

/**
 * What one nested value of a cascade is, and where it goes.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class CascadeItem {

	/**
	 * The shapes of an existing object's identifier.
	 */
	private const IDENTIFIER_PATTERNS = [
		'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
		'/^[0-9a-f]{32}$/i',
		'/^[a-z]+-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32})$/i',
		'/^[0-9]+$/',
	];

	/**
	 * Whether a list item can cascade: an object with data, or an existing object's identifier.
	 *
	 * @param mixed $object The item.
	 *
	 * @return bool True for a candidate.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function isCandidate(mixed $object): bool {
		if (is_string($object) === true) {
			return self::isObjectIdentifier(value: $object);
		}

		return is_array($object) === true && empty($object) === false && self::hasOnlyEmptyId(object: $object) === false;
	}//end isCandidate()

	/**
	 * Whether an object holds nothing but an empty id.
	 *
	 * @param array $object The object.
	 *
	 * @return bool True when it has no actual data.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function hasOnlyEmptyId(array $object): bool {
		return count($object) === 1
			&& ($object['id'] ?? null) !== null
			&& empty($object['id']) === true;
	}//end hasOnlyEmptyId()

	/**
	 * Whether a string has the shape of an existing object's identifier.
	 *
	 * @param string $value The string.
	 *
	 * @return bool True for a UUID in any form, or a numeric id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function isObjectIdentifier(string $value): bool {
		foreach (self::IDENTIFIER_PATTERNS as $pattern) {
			if (preg_match($pattern, $value) === 1) {
				return true;
			}
		}

		return false;
	}//end isObjectIdentifier()

	/**
	 * The inversedBy value of a nested object, pointing at its parent.
	 *
	 * @param mixed  $current  The value the object carries.
	 * @param string $parentId The parent's UUID.
	 *
	 * @return mixed The parent's UUID, or the list with it added.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function withParentReference(mixed $current, string $parentId): mixed {
		if (is_array($current) === false) {
			return $parentId;
		}

		if (in_array($parentId, $current, true) === false) {
			$current[] = $parentId;
		}

		return $current;
	}//end withParentReference()

	/**
	 * The register a nested object is saved in.
	 *
	 * @param array $definition     The nested object's definition.
	 * @param mixed $parentRegister The parent's register.
	 *
	 * @return mixed The register.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public static function register(array $definition, mixed $parentRegister): mixed {
		$register = $definition['register'] ?? $parentRegister;
		if (is_array($register) === true) {
			$register = $register['id'] ?? $register;
		}

		return $register;
	}//end register()
}//end class
