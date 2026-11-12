<?php

/**
 * One conversion of an amount of business time from one unit to another.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * An amount in one unit, the unit wanted, and the calendar that says how many
 * hours a business day has.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
 */
final class UnitConversion {
	/**
	 * Describe the conversion.
	 *
	 * @param float           $value    The amount.
	 * @param string          $fromUnit The unit it is in.
	 * @param string          $toUnit   The unit wanted.
	 * @param WorkingCalendar $calendar Supplies hoursPerWorkingDay.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
	 */
	public function __construct(
		public readonly float $value,
		public readonly string $fromUnit,
		public readonly string $toUnit,
		public readonly WorkingCalendar $calendar,
	) {
	}//end __construct()
}//end class
