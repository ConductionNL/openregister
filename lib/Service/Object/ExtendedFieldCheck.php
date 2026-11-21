<?php

/**
 * ExtendedFieldCheck
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Formats\FormattedProperty;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Formats\FormatFactory;

/**
 * Checks an object's values against the extended field types (color, recurrence,
 * calendar-range, image-url).
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */
final class ExtendedFieldCheck {
	/**
	 * Start a check.
	 *
	 * @param array $object The object data
	 */
	public function __construct(
		private readonly array $object,
	) {
	}//end __construct()

	/**
	 * Refuse the first value that breaks its declared extended type.
	 *
	 * @param object $schemaObject The original, un-transformed schema
	 *
	 * @return void
	 *
	 * @throws CustomValidationException When a value breaks its declared type
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	public function assertValid(object $schemaObject): void {
		$properties = ($schemaObject->properties ?? null);
		if (is_object($properties) === false && is_array($properties) === false) {
			return;
		}

		foreach ($properties as $name => $propertySchema) {
			$error = $this->errorFor(name: (string)$name, declared: self::declaration(propertySchema: $propertySchema));
			if ($error !== null) {
				throw new CustomValidationException(message: $error, errors: [(string)$name => $error]);
			}
		}
	}//end assertValid()

	/**
	 * A property's declaration, which may be an object or an array.
	 *
	 * @param mixed $propertySchema The property schema
	 *
	 * @return array The declaration, empty for anything else
	 */
	private static function declaration(mixed $propertySchema): array {
		if (is_object($propertySchema) === true) {
			return get_object_vars($propertySchema);
		}

		if (is_array($propertySchema) === true) {
			return $propertySchema;
		}

		return [];
	}//end declaration()

	/**
	 * The error for one property; a property not submitted or null has nothing to validate.
	 *
	 * @param string $name     The property name
	 * @param array  $declared The property's declaration
	 *
	 * @return string|null The error
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	private function errorFor(string $name, array $declared): ?string {
		$value = ($this->object[$name] ?? null);
		if ($value === null) {
			return null;
		}

		return match ($declared['type'] ?? null) {
			'color' => FormatFactory::extendedFieldTypes()->validateColorProperty(
				value: $value,
				property: new FormattedProperty(name: $name, format: ($declared['format'] ?? null))
			),
			'recurrence' => FormatFactory::extendedFieldTypes()->validateRecurrence(value: $value, propertyName: $name),
			'calendar-range' => FormatFactory::extendedFieldTypes()->validateCalendarRange(
				value: $value,
				property: new FormattedProperty(name: $name, format: ($declared['format'] ?? null))
			),
			'image-url' => FormatFactory::extendedFieldTypes()->validateImageUrl(value: $value, propertyName: $name),
			default => null,
		};
	}//end errorFor()
}//end class
