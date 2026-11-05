<?php

/**
 * OpenRegister Calendar Picker Scan
 *
 * One pass over a calendar's objects for the event picker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The calendar being listed and the start-time cutoff; turns VEVENTs into picker rows.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class CalendarPickerScan {

	/**
	 * Unix timestamp lower bound for an event's start, null for none.
	 *
	 * @var integer|null
	 */
	public ?int $cutoff = null;

	/**
	 * Constructor.
	 *
	 * @param int    $calendarId  Numeric calendar id.
	 * @param string $calendarUri Calendar URI.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly int $calendarId,
		public readonly string $calendarUri,
	) {
	}//end __construct()

	/**
	 * A picker row for a VEVENT; null when there is none, it has no UID, or it starts before the cutoff.
	 *
	 * @param mixed  $vevent    The object's VEVENT component (Sabre magic property), null when there is none.
	 * @param string $objectUri CalDAV object URI.
	 *
	 * @return array<string,mixed>|null Picker row, or null to skip.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function pickerRow(mixed $vevent, string $objectUri): ?array {
		if ($vevent === null || isset($vevent->UID) === false) {
			return null;
		}

		$start = null;
		if (isset($vevent->DTSTART) === true) {
			$start = $vevent->DTSTART->getDateTime();
		}

		if ($this->cutoff !== null && $start !== null && $start->getTimestamp() < $this->cutoff) {
			return null;
		}

		$dtend = null;
		if (isset($vevent->DTEND) === true) {
			$dtend = $vevent->DTEND->getDateTime()->format('c');
		}

		$location = null;
		if (isset($vevent->LOCATION) === true) {
			$location = (string)$vevent->LOCATION;
		}

		return [
			'uid' => (string)$vevent->UID,
			'uri' => $objectUri,
			'summary' => (string)($vevent->SUMMARY ?? ''),
			'dtstart' => $start?->format('c'),
			'dtend' => $dtend,
			'location' => $location,
			'calendarId' => $this->calendarId,
			'calendarUri' => $this->calendarUri,
		];
	}//end pickerRow()
}//end class
