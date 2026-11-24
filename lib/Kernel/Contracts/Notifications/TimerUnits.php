<?php
/**
 * TimerUnits: the units a notification reminder amount and a timer offset are declared in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

/**
 * The unit vocabulary of a declared amount (`{value, unit}`).
 *
 * The reminder validator (Notifications) checks a reminder amount against it;
 * the SLA calculator (Processes) defines its own units as these.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class TimerUnits {

	/**
	 * Clock hours.
	 *
	 * @var string
	 */
	public const UNIT_HOURS = 'hours';

	/**
	 * Working days of the business calendar.
	 *
	 * @var string
	 */
	public const UNIT_BUSINESS_DAYS = 'businessDays';

	/**
	 * Calendar days.
	 *
	 * @var string
	 */
	public const UNIT_CALENDAR_DAYS = 'calendarDays';

	/**
	 * Every accepted unit.
	 *
	 * @var array<int, string>
	 */
	public const UNITS = [self::UNIT_HOURS, self::UNIT_BUSINESS_DAYS, self::UNIT_CALENDAR_DAYS];
}//end class
