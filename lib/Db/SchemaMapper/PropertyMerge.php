<?php

/**
 * PropertyMerge: deep merge of schema property definitions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

/**
 * Merges property definitions without validating them.
 *
 * This does NOT enforce the Liskov Substitution Principle; use
 * SchemaPropertyInheritance::mergeProperties() for extension scenarios.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class PropertyMerge {

	/**
	 * Merge parent and child schema properties.
	 *
	 * - Properties present in both parent and child: child values override parent values
	 * - Properties only in parent: included in result
	 * - Properties only in child: included in result
	 * - For nested properties (objects), performs recursive merge
	 *
	 * @param array<mixed> $parentProperties Parent schema properties.
	 * @param array<mixed> $childProperties  Child schema properties (overrides).
	 *
	 * @return array<mixed> Merged properties array.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function merge(array $parentProperties, array $childProperties): array {
		$merged = $parentProperties;
		foreach ($childProperties as $propertyName => $propertyDefinition) {
			if (is_array($propertyDefinition) === true && is_array(($merged[$propertyName] ?? null)) === true) {
				$merged[$propertyName] = $this->deepMerge(parentProperty: $merged[$propertyName], childProperty: $propertyDefinition);
				continue;
			}

			$merged[$propertyName] = $propertyDefinition;
		}

		return $merged;
	}//end merge()

	/**
	 * Perform a deep merge of a single property definition.
	 *
	 * Examples:
	 * - Parent has 'minLength': 5, child has 'maxLength': 100 -> both are preserved
	 * - Parent has 'title': 'Name', child has 'title': 'Full Name' -> child overrides
	 * - 'properties' and 'items' merge recursively; other arrays (enum, required) are replaced
	 *
	 * @param array<mixed> $parentProperty Parent property definition.
	 * @param array<mixed> $childProperty  Child property definition (overrides).
	 *
	 * @return array<mixed> Merged property definition.
	 */
	private function deepMerge(array $parentProperty, array $childProperty): array {
		$merged = $parentProperty;
		foreach ($childProperty as $key => $value) {
			$nested = in_array($key, ['properties', 'items'], true);
			if ($nested === true && is_array($value) === true && is_array(($merged[$key] ?? null)) === true) {
				$merged[$key] = $this->deepMerge(parentProperty: $merged[$key], childProperty: $value);
				continue;
			}

			$merged[$key] = $value;
		}

		return $merged;
	}//end deepMerge()
}//end class
