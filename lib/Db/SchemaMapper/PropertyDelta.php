<?php

/**
 * PropertyDelta: what a child schema's properties add to those of its parents.
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
 * Keeps only what differs from the parent, so a composed schema stores its delta.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class PropertyDelta {

	/**
	 * Extract properties that differ from the parent.
	 *
	 * @param array<mixed> $parentProperties Parent schema properties.
	 * @param array<mixed> $childProperties  Child schema properties.
	 *
	 * @return array<mixed> Properties that differ from the parent.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function between(array $parentProperties, array $childProperties): array {
		$delta = $this->newOrDifferent(parent: $parentProperties, child: $childProperties);
		foreach ($delta as $propertyName => $childProperty) {
			if (is_array($childProperty) === true && is_array(($parentProperties[$propertyName] ?? null)) === true) {
				$delta[$propertyName] = $this->ofObject(parent: $parentProperties[$propertyName], child: $childProperty);
			}
		}

		return $delta;
	}//end between()

	/**
	 * The differing fields of a changed property; nested `properties` keep only their own delta.
	 *
	 * @param array<mixed> $parent Parent property definition.
	 * @param array<mixed> $child  Child property definition.
	 *
	 * @return array<mixed>
	 */
	private function ofObject(array $parent, array $child): array {
		$delta = $this->newOrDifferent(parent: $parent, child: $child);
		if (is_array(($delta['properties'] ?? null)) === true && is_array(($parent['properties'] ?? null)) === true) {
			$delta['properties'] = $this->between(parentProperties: $parent['properties'], childProperties: $delta['properties']);
		}

		return $delta;
	}//end ofObject()

	/**
	 * The entries of the child the parent lacks or holds differently.
	 *
	 * @param array<mixed> $parent The parent entries.
	 * @param array<mixed> $child  The child entries.
	 *
	 * @return array<mixed>
	 */
	private function newOrDifferent(array $parent, array $child): array {
		$delta = [];
		foreach ($child as $key => $value) {
			if (isset($parent[$key]) === false || $this->differ(parent: $parent[$key], child: $value) === true) {
				$delta[$key] = $value;
			}
		}

		return $delta;
	}//end newOrDifferent()

	/**
	 * Whether two definitions differ; JSON handles arrays, objects and scalars uniformly.
	 *
	 * @param mixed $parent Parent definition.
	 * @param mixed $child  Child definition.
	 *
	 * @return bool
	 */
	private function differ(mixed $parent, mixed $child): bool {
		return json_encode($parent) !== json_encode($child);
	}//end differ()
}//end class
