<?php

/**
 * The mask of one history change of an explicit-reveal property.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Masks a change: `{old, new}` side by side, or a plain value.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal::mask() is a pure function.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
final class ExplicitRevealChangeMask {

	/**
	 * Mask one change: `{old, new}` side by side, or a plain value. An array
	 * value (a list, an object) has no tail to show and becomes five stars.
	 *
	 * @param mixed $change The change.
	 *
	 * @return mixed The change, masked.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public static function maskChange(mixed $change): mixed {
		if (self::isDiff(change: $change) === false) {
			return self::maskValue(value: $change);
		}

		foreach (['old', 'new'] as $side) {
			if (isset($change[$side]) === true) {
				$change[$side] = self::maskValue(value: $change[$side]);
			}
		}

		return $change;
	}//end maskChange()

	/**
	 * Whether a change is an `{old, new}` diff: an array carrying either side.
	 *
	 * @param mixed $change The change.
	 *
	 * @return bool
	 */
	private static function isDiff(mixed $change): bool {
		return is_array($change) === true
			&& (array_key_exists('old', $change) === true || array_key_exists('new', $change) === true);
	}//end isDiff()

	/**
	 * Mask a scalar, or replace an array by five stars.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The mask; null stays null.
	 */
	private static function maskValue(mixed $value): mixed {
		if (is_array($value) === true) {
			return str_repeat('*', 5);
		}

		if ($value === null) {
			return null;
		}

		return ExplicitReveal::mask(value: $value);
	}//end maskValue()
}//end class
