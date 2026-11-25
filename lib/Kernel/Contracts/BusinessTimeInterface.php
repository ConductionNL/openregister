<?php
/**
 * BusinessTimeInterface: counts an amount of calendar or business time from a moment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Business-time arithmetic for callers outside the flow timers (e.g. scheduled reminders).
 *
 * Units are the timer units of {@see \OCA\OpenRegister\Kernel\Contracts\Notifications\TimerUnits}.
 * Bound to \OCA\OpenRegister\Service\Flow\Timer\BusinessTime.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
interface BusinessTimeInterface {

	/**
	 * The working calendar an organisation counts business time on.
	 *
	 * @param string|null $organisation The organisation, or null for the default calendar.
	 *
	 * @return BusinessCalendarInterface The calendar.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When the calendar cannot be resolved.
	 */
	public function calendarFor(?string $organisation): BusinessCalendarInterface;

	/**
	 * Add an amount to a moment.
	 *
	 * @param DateTimeImmutable              $start    The start.
	 * @param float                          $value    The amount (negative counts back).
	 * @param string                         $unit     A timer unit.
	 * @param BusinessCalendarInterface|null $calendar The calendar business units are counted on.
	 *
	 * @return DateTimeImmutable The result.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When a business unit has no calendar or the unit is unknown.
	 */
	public function add(DateTimeImmutable $start, float $value, string $unit, ?BusinessCalendarInterface $calendar): DateTimeImmutable;
}//end interface
