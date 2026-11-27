<?php
/**
 * BusinessCalendarInterface: the working calendar business time is counted on, as seen outside the timers.
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

/**
 * A working calendar handed out by {@see BusinessTimeInterface}.
 *
 * Callers outside the flow timers only read its time zone (a date without a
 * time is midnight there) and hand it back to {@see BusinessTimeInterface::add()}.
 * Implemented by \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/notificatie-engine/spec.md
 */
interface BusinessCalendarInterface {

	/**
	 * The calendar's IANA time zone.
	 *
	 * @return string The zone, e.g. Europe/Amsterdam.
	 */
	public function getTimezone(): string;
}//end interface
