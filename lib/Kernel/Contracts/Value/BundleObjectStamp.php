<?php

/**
 * OpenRegister Bundle Object Stamp
 *
 * One property value written onto the objects of named schemas in a bundle
 * before it is imported, such as the world a store install goes into.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * A property value stamped on every object of the named schemas in a bundle.
 *
 * The stamp is applied AFTER the bundle's signature is checked, to a copy:
 * the publisher signed what it published, and the install target is the
 * installing organisation's own choice, not part of the publication.
 *
 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
 */
final class BundleObjectStamp {

	/**
	 * Constructor.
	 *
	 * @param string             $property The property written on each object.
	 * @param array<int, string> $schemas  The schema slugs whose objects are stamped.
	 * @param string             $value    The value written.
	 */
	public function __construct(
		public readonly string $property,
		public readonly array $schemas,
		public readonly string $value,
	) {
	}//end __construct()

	/**
	 * Whether this stamp writes anything at all.
	 *
	 * @return bool True when a property, a value and at least one schema are set.
	 */
	public function isEmpty(): bool {
		return $this->property === '' || $this->value === '' || $this->schemas === [];
	}//end isEmpty()

	/**
	 * The bundle with every object of a stamped schema carrying the value.
	 *
	 * Objects are read where an import reads them: `components.objects` and
	 * the top-level `objects` list. An object's schema is its `@self.schema`.
	 *
	 * @param array<string, mixed> $bundle The verified bundle.
	 *
	 * @return array<string, mixed> The stamped copy.
	 *
	 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
	 */
	public function applyTo(array $bundle): array {
		if ($this->isEmpty() === true) {
			return $bundle;
		}

		if (is_array($bundle['components']['objects'] ?? null) === true) {
			$bundle['components']['objects'] = $this->stampList(objects: $bundle['components']['objects']);
		}

		if (is_array($bundle['objects'] ?? null) === true) {
			$bundle['objects'] = $this->stampList(objects: $bundle['objects']);
		}

		return $bundle;
	}//end applyTo()

	/**
	 * One object, stamped when its schema is one of the stamped schemas.
	 *
	 * @param string               $schema The object's schema slug.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return array<string, mixed> The object, stamped or untouched.
	 */
	public function applyToObject(string $schema, array $object): array {
		if ($this->isEmpty() === true || in_array($schema, $this->schemas, true) === false) {
			return $object;
		}

		$object[$this->property] = $this->value;
		return $object;
	}//end applyToObject()

	/**
	 * A list of bundle objects with the stamped ones changed.
	 *
	 * @param array<int|string, mixed> $objects The bundle's objects.
	 *
	 * @return array<int|string, mixed> The list, same keys.
	 */
	private function stampList(array $objects): array {
		foreach ($objects as $key => $object) {
			if (is_array($object) === false) {
				continue;
			}

			$schema        = (string)($object['@self']['schema'] ?? '');
			$objects[$key] = $this->applyToObject(schema: $schema, object: $object);
		}

		return $objects;
	}//end stampList()
}//end class
