<?php

/**
 * PropertyAuthorization: the property-level authorization of a schema.
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

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Service\Schemas\ScopedPropertyDeclaration;

/**
 * Which properties are governed, and by which authorization block, a `scope` compiled into one.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class PropertyAuthorization {

	/**
	 * Check if any property in the schema has authorization rules defined.
	 *
	 * This is used to determine if property-level RBAC filtering needs to be applied
	 * during object rendering or validation.
	 *
	 * @param array|null $properties The schema's properties.
	 *
	 * @return bool True if at least one property has non-empty authorization
	 */
	public function hasAny(?array $properties): bool {
		foreach (($properties ?? []) as $propertyConfig) {
			if ($this->carries(propertyConfig: $propertyConfig) === true) {
				return true;
			}
		}

		return false;
	}//end hasAny()

	/**
	 * Get the authorization rules for a specific property.
	 *
	 * @param array|null $properties   The schema's properties.
	 * @param string     $propertyName The name of the property
	 *
	 * @return array|null The authorization rules or null if none defined
	 */
	public function forProperty(?array $properties, string $propertyName): ?array {
		$propertyConfig = ($properties[$propertyName] ?? null);
		if (is_array($propertyConfig) === false) {
			return null;
		}

		$authorization = $propertyConfig['authorization'] ?? null;
		if (empty($authorization) === false) {
			return $authorization;
		}

		return $this->compiledScope(propertyConfig: $propertyConfig);
	}//end forProperty()

	/**
	 * Get all properties that have authorization rules defined.
	 *
	 * Read through the same compiler the single-property lookup uses, so
	 * a scoped property is listed with the block it actually enforces
	 * rather than with nothing.
	 *
	 * @param array|null $properties The schema's properties.
	 *
	 * @return array<string, array> Map of property names to their authorization rules
	 */
	public function all(?array $properties): array {
		$result = [];
		foreach (($properties ?? []) as $propertyName => $propertyConfig) {
			if ($this->carries(propertyConfig: $propertyConfig) === true) {
				$result[$propertyName] = $this->forProperty(properties: $properties, propertyName: (string)$propertyName);
			}
		}

		return $result;
	}//end all()

	/**
	 * The authorization block a property's `scope` compiles to; null without a scope.
	 *
	 * 🔴 A `scope` IS AN AUTHORIZATION BLOCK, AND THIS IS WHERE IT
	 * BECOMES ONE. Compiling it here rather than beside the existing
	 * mechanism is the whole design: `PropertyRbacHandler` already
	 * strips unreadable properties from every read, refuses writes to
	 * them, and keeps them out of exports and the OAS, all by reading
	 * Schema::getPropertyAuthorization(), which answers with this. A second evaluator would mean two answers to "may
	 * this person see this field", and the two disagree within a week.
	 *
	 * Without this, `scope` would validate, publish, and enforce
	 * nothing, and the author would believe the field was team-only
	 * BECAUSE the platform accepted the word.
	 *
	 * @param array $propertyConfig The property's configuration, without an authorization block.
	 *
	 * @return array|null
	 */
	private function compiledScope(array $propertyConfig): ?array {
		$scope = ($propertyConfig[ScopedPropertyDeclaration::ANNOTATION] ?? null);
		if (is_string($scope) === true && trim($scope) !== '') {
			return ScopedPropertyDeclaration::authorizationFor(scope: trim($scope));
		}

		return null;
	}//end compiledScope()

	/**
	 * Whether one property config is governed at all.
	 *
	 * 🔴 THIS METHOD IS THE REASON `scope` IS NOT INERT, AND THE TRAP IS THAT
	 * NOTHING WOULD HAVE FAILED WITHOUT IT. `hasPropertyAuthorization()` is a
	 * SHORT-CIRCUIT: five call sites, on the render, query, export and OAS
	 * paths, skip property filtering entirely when it answers false. Compiling
	 * a scope into an authorization block inside
	 * {@see forProperty()} is therefore not enough on its own,
	 * because on a schema whose only control is a scope nothing would ever call
	 * it. The field would be published as scoped and returned to everybody, and
	 * no test on the compiler itself could see it.
	 *
	 * So both gates ask this one question, and a scope answers it.
	 *
	 * @param mixed $propertyConfig One property's configuration.
	 *
	 * @return bool Whether the property is governed by an authorization block or a scope.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	private function carries(mixed $propertyConfig): bool {
		if (is_array($propertyConfig) === false) {
			return false;
		}

		if (empty($propertyConfig['authorization'] ?? null) === false) {
			return true;
		}

		$scope = ($propertyConfig[ScopedPropertyDeclaration::ANNOTATION] ?? null);

		return (is_string($scope) === true && trim($scope) !== '');
	}//end carries()
}//end class
