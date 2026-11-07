<?php

/**
 * SchemaReferenceSearch: finds a reference to one schema in a property tree.
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
 * Searches properties recursively for a `$ref` to the target schema.
 *
 * @spec exclude schema read plumbing moved out of SchemaMapper, not a capability of its own
 */
final class SchemaReferenceSearch {

	/**
	 * Constructor.
	 *
	 * @param array{id: string, uuid: string, slug: string} $target The target schema's identifiers.
	 */
	public function __construct(
		private readonly array $target,
	) {
	}//end __construct()

	/**
	 * Whether properties, nested properties or array items reference the target.
	 *
	 * @param array<mixed> $properties The properties to search through.
	 *
	 * @return bool
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function foundIn(array $properties): bool {
		foreach (array_filter($properties, 'is_array') as $property) {
			if ($this->propertyReferences(property: $property) === true) {
				return true;
			}
		}

		return false;
	}//end foundIn()

	/**
	 * Whether one property, or anything nested in it, references the target.
	 *
	 * @param array<mixed> $property The property definition.
	 *
	 * @return bool
	 */
	private function propertyReferences(array $property): bool {
		if ($this->matches(ref: ($property['$ref'] ?? null)) === true) {
			return true;
		}

		if (is_array(($property['properties'] ?? null)) === true && $this->foundIn(properties: $property['properties']) === true) {
			return true;
		}

		return is_array(($property['items'] ?? null)) === true && $this->foundIn(properties: [$property['items']]) === true;
	}//end propertyReferences()

	/**
	 * Whether a `$ref` value names the target, exactly or inside a path.
	 *
	 * @param mixed $ref The `$ref` value.
	 *
	 * @return bool
	 */
	private function matches(mixed $ref): bool {
		if ($ref === null || $ref === '') {
			return false;
		}

		return $this->equalsTarget(ref: $ref) === true || (is_string($ref) === true && $this->namedIn(ref: $ref) === true);
	}//end matches()

	/**
	 * Whether a reference string names the target inside a longer path.
	 *
	 * @param string $ref The reference.
	 *
	 * @return bool
	 */
	private function namedIn(string $ref): bool {
		return $this->pathNamesSlug(ref: $ref) === true || $this->containsUuid(ref: $ref) === true;
	}//end namedIn()

	/**
	 * Whether a `$ref` value is the target's id, uuid or slug.
	 *
	 * @param mixed $ref The `$ref` value.
	 *
	 * @return bool
	 */
	private function equalsTarget(mixed $ref): bool {
		$exact = [$this->target['id'], $this->target['uuid'], $this->target['slug'], (int)$this->target['id']];

		return in_array($ref, $exact, true);
	}//end equalsTarget()

	/**
	 * Whether a reference path holds the target's slug: "#/components/schemas/slug" and the like.
	 *
	 * The slug must end there: `zaak` is not a reference to `schemas/zaaktype`.
	 *
	 * @param string $ref The reference.
	 *
	 * @return bool
	 */
	private function pathNamesSlug(string $ref): bool {
		$slug = $this->target['slug'];
		if (empty($slug) === true) {
			return false;
		}

		$inPath = preg_match('#schemas/' . preg_quote($slug, '#') . '(?![A-Za-z0-9_-])#', $ref) === 1;

		return $inPath === true || str_ends_with($ref, '/' . $slug) === true;
	}//end pathNamesSlug()

	/**
	 * Whether a reference holds the target's uuid.
	 *
	 * @param string $ref The reference.
	 *
	 * @return bool
	 */
	private function containsUuid(string $ref): bool {
		return empty($this->target['uuid']) === false && str_contains($ref, $this->target['uuid']) === true;
	}//end containsUuid()
}//end class
