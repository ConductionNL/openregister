<?php

/**
 * OpenRegister ReferenceStrictness
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
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * How strictly a schema property wants its references validated.
 *
 * `validateReference` is the switch: a boolean, or a string that also carries the
 * severity. `validationStrictness` overrides the severity, and `off` there disables
 * validation whatever the switch says.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class ReferenceStrictness {

	/**
	 * The severity each spelling stands for.
	 */
	private const LEVELS = ['strict' => 'error', 'error' => 'error', 'block' => 'error', 'warn' => 'warn'];

	/**
	 * The severity for a property.
	 *
	 * @param array $property The schema property definition.
	 *
	 * @return string|null `error`, `warn`, or null when validation is off.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function forProperty(array $property): ?string {
		$explicit = ($property['validationStrictness'] ?? null);
		$configured = self::configured(value: ($property['validateReference'] ?? false));
		if ($configured === null || (is_string($explicit) === true && strtolower($explicit) === 'off')) {
			return null;
		}

		return (self::level(value: $explicit) ?? $configured);
	}//end forProperty()

	/**
	 * The severity the `validateReference` switch asks for.
	 *
	 * @param mixed $value The switch.
	 *
	 * @return string|null The severity, or null when it is off.
	 */
	private static function configured(mixed $value): ?string {
		if ($value === true) {
			return 'error';
		}

		return self::level(value: $value);
	}//end configured()

	/**
	 * The severity a spelling stands for.
	 *
	 * @param mixed $value The spelling.
	 *
	 * @return string|null The severity; null for anything but a known string.
	 */
	private static function level(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		return (self::LEVELS[strtolower($value)] ?? null);
	}//end level()
}//end class
