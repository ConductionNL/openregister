<?php

/**
 * OpenRegister RelationPropertyShape
 *
 * Whether a schema property definition references other objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * A schema property definition, read for whether it is a relation.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final readonly class RelationPropertyShape {

	/**
	 * Constructor.
	 *
	 * @param array $property The schema property definition.
	 */
	public function __construct(
		private array $property,
	) {
	}//end __construct()

	/**
	 * The UUID a value of this property refers to.
	 *
	 * @param mixed $value The property's value.
	 *
	 * @return string|null The UUID, or null when the property is not a relation
	 *                     or the value holds no UUID.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function uuidIn(mixed $value): ?string {
		if ($this->isRelation() === false) {
			return null;
		}

		return (new UuidReference(value: $value))->uuid();
	}//end uuidIn()

	/**
	 * Check if the property is a relation (references another object).
	 *
	 * A relation has a $ref pointing to another schema, has format: uuid, or
	 * has relation items (see hasRelationItems()).
	 *
	 * @return bool True if the property is a relation.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isRelation(): bool {
		if (empty($this->property['$ref']) === false || ($this->property['format'] ?? '') === 'uuid') {
			return true;
		}

		return $this->hasRelationItems();
	}//end isRelation()

	/**
	 * Whether array items have $ref or format: uuid, or object items have a
	 * oneOf option with a $ref.
	 *
	 * @return bool True if the items reference other objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function hasRelationItems(): bool {
		if (($this->property['type'] ?? '') === 'array' && isset($this->property['items']) === true) {
			return $this->isRelationItem(items: $this->property['items']);
		}

		return $this->hasRefOneOf();
	}//end hasRelationItems()

	/**
	 * Whether array items have a $ref or format: uuid.
	 *
	 * @param mixed $items The items definition.
	 *
	 * @return bool True if the items reference other objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function isRelationItem(mixed $items): bool {
		return empty($items['$ref']) === false || ($items['format'] ?? '') === 'uuid';
	}//end isRelationItem()

	/**
	 * Whether an object property's items have a oneOf option with a $ref.
	 *
	 * @return bool True if an option references another schema.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function hasRefOneOf(): bool {
		foreach ($this->objectOneOf() as $option) {
			if (empty($option['$ref']) === false) {
				return true;
			}
		}

		return false;
	}//end hasRefOneOf()

	/**
	 * The oneOf options of an object property's items.
	 *
	 * @return mixed The options as declared, or [] when the property is not an
	 *               object or its items declare no oneOf.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function objectOneOf(): mixed {
		if (($this->property['type'] ?? '') === 'object' && isset($this->property['items']['oneOf']) === true) {
			return $this->property['items']['oneOf'];
		}

		return [];
	}//end objectOneOf()
}//end class
