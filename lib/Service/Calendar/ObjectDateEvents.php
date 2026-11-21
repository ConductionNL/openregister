<?php

/**
 * The events one calendar scope holds for one principal.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeZone;

/**
 * The output of the one event generator both calendar surfaces read: the
 * scope's display name, the tenant zone and one entry per generated VEVENT.
 *
 * The subscribable feed folds these into a VCALENDAR body; the platform
 * calendar turns each entry into a search result. Neither builds an event
 * of its own, so the two cannot disagree about a date.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */
final readonly class ObjectDateEvents {

	/**
	 * Constructor.
	 *
	 * @param string                                                         $name     The scope's display name.
	 * @param DateTimeZone                                                   $timeZone The tenant zone timed events carry.
	 * @param array<int, array{lines: array<int, string>, years: array<int, int>}> $events   One entry per VEVENT.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function __construct(
		public string $name,
		public DateTimeZone $timeZone,
		public array $events,
	) {

	}//end __construct()

	/**
	 * The unfolded lines of every event, in order.
	 *
	 * @return array<int, string> The lines.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function lines(): array {
		$lines = [];
		foreach ($this->events as $event) {
			$lines = array_merge($lines, $event['lines']);
		}

		return $lines;
	}//end lines()

	/**
	 * The years every event touches.
	 *
	 * @return array<int, int> The years, possibly empty.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function years(): array {
		$years = [];
		foreach ($this->events as $event) {
			$years = array_merge($years, $event['years']);
		}

		return $years;
	}//end years()
}//end class
