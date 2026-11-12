<?php

/**
 * PropertyReferenceValidationTrait — the part the schema-property reference
 * validators share: walking a `properties` map and phrasing an error.
 *
 * {@see PropertyReferenceTypeValidator} and
 * {@see PropertySemanticReferenceValidator} each check their own marker on
 * one property definition; how a whole map is walked and how the property
 * name is put in front of the message is the same for both.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use InvalidArgumentException;

/**
 * Walks a `properties` map through a per-property `validate()`.
 */
trait PropertyReferenceValidationTrait {

	/**
	 * Validate a single property definition.
	 *
	 * @param array<string,mixed> $property     Schema property definition.
	 * @param string|null         $propertyName Optional property name for error messages.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the property's marker is invalid.
	 */
	abstract public function validate(array $property, ?string $propertyName = null): void;

	/**
	 * Validate every property in a schema's `properties` map.
	 *
	 * Entries that are not definitions (not arrays) are skipped; a numeric
	 * key is reported without a property name.
	 *
	 * @param array<string,array<string,mixed>> $properties Property map.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On the first invalid property.
	 */
	public function validateAll(array $properties): void {
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === true) {
				$this->validate(property: $definition, propertyName: $this->propertyNameOf(name: $name));
			}
		}
	}//end validateAll()

	/**
	 * The name to report a map entry under.
	 *
	 * @param int|string $name The map key.
	 *
	 * @return string|null The key when it is a string, else null.
	 */
	protected function propertyNameOf(int|string $name): ?string {
		if (is_string($name) === true) {
			return $name;
		}

		return null;
	}//end propertyNameOf()

	/**
	 * Throw the standard error for a problem, when there is one.
	 *
	 * @param string|null $propertyName Property name (or null for schema-level errors).
	 * @param string|null $problem      The offending keyword and what is wrong with it, or null.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When `$problem` is not null.
	 */
	protected function refuse(?string $propertyName, ?string $problem): void {
		if ($problem === null) {
			return;
		}

		if ($propertyName !== null) {
			$problem = sprintf("Property '%s' %s", $propertyName, $problem);
		}

		throw new InvalidArgumentException($problem);
	}//end refuse()
}//end class
