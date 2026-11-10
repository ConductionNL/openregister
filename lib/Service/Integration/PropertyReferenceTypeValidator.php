<?php

/**
 * PropertyReferenceTypeValidator — validate the `referenceType` marker
 * on schema property definitions (AD-18).
 *
 * Schema properties may declare `referenceType: <integration-id>` to
 * indicate they hold a reference to an entity managed by that
 * integration. `CnFormDialog` and `CnDetailGrid` then render the
 * matching integration's `single-entity` widget surface inline
 * instead of a raw uuid string.
 *
 * This validator enforces:
 *   - referenceType is a string when present (null / absent is OK)
 *   - the value matches an id currently registered in
 *     `IntegrationRegistry`
 *
 * Backwards-compat: schemas without `referenceType` validate as
 * before. Adding the marker is opt-in; removing it from a schema is
 * also a no-op for validation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use InvalidArgumentException;

/**
 * Validate the optional `referenceType` marker on schema properties.
 */
class PropertyReferenceTypeValidator {
	use PropertyReferenceValidationTrait;

	/**
	 * Constructor.
	 *
	 * @param IntegrationIdIndexInterface $registry The integration registry —
	 *                                      used to look up valid ids.
	 *
	 * @return void
	 */
	public function __construct(
		private IntegrationIdIndexInterface $registry,
	) {
	}//end __construct()

	/**
	 * Validate a single property definition.
	 *
	 * Properties without a `referenceType` key pass through. Properties
	 * with one MUST carry a string that matches a currently-registered
	 * integration id; otherwise this throws.
	 *
	 * @param array<string,mixed> $property Schema property definition.
	 * @param string|null $propertyName Optional property name
	 *                                  for error messages.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When referenceType is non-string
	 *                                  or refers to an unregistered id.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-10
	 */
	public function validate(array $property, ?string $propertyName = null): void {
		$value = ($property['referenceType'] ?? null);
		if ($value === null) {
			return;
		}

		$this->refuse(propertyName: $propertyName, problem: $this->problemWith(value: $value));
	}//end validate()

	/**
	 * What is wrong with a non-null `referenceType`, if anything.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return string|null The problem, or null when it names a registered integration.
	 */
	private function problemWith(mixed $value): ?string {
		if (is_string($value) === false) {
			return 'referenceType must be a string or null';
		}

		if ($value === '') {
			return 'referenceType must not be empty';
		}

		if ($this->registry->isValidIntegrationId($value) === false) {
			return sprintf("referenceType refers to unregistered integration '%s'. Registered ids: %s", $value, $this->registeredIdList());
		}

		return null;
	}//end problemWith()

	/**
	 * The registered integration ids, sorted, for an error message.
	 *
	 * @return string The comma-separated ids, or `(none)`.
	 */
	private function registeredIdList(): string {
		$validIds = $this->registry->listIds();
		sort($validIds);
		if ($validIds === []) {
			return '(none)';
		}

		return implode(', ', $validIds);
	}//end registeredIdList()
}//end class
