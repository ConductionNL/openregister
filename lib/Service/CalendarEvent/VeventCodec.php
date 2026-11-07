<?php

/**
 * VeventCodec
 *
 * Reads VEVENT components into event arrays and serialises new OpenRegister-linked VEVENTs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\CalendarEvent
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\CalendarEvent;

use DateTime;
use Exception;

/**
 * The iCalendar side of CalendarEventService: event arrays out of parsed VEVENTs,
 * and the text of new events linked to an OpenRegister object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class VeventCodec {

	/**
	 * The iCalendar text of a new event.
	 *
	 * @param string   $uid     The event UID.
	 * @param DateTime $dtstart The start time.
	 * @param array    $data    Event data: summary, dtend, location, description, attendees.
	 * @param array    $link    The linked object: registerId, schemaId, objectUuid, objectTitle.
	 *
	 * @return string The CRLF-terminated iCalendar text.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function calendarData(string $uid, DateTime $dtstart, array $data, array $link): string {
		$lines = array_merge(
			$this->eventHeaderLines(uid: $uid, summary: ($data['summary'] ?? 'Untitled event'), dtstart: $dtstart),
			$this->eventDetailLines(data: $data),
			$this->eventLinkLines(link: $link),
			['END:VEVENT', 'END:VCALENDAR']
		);

		return implode("\r\n", $lines) . "\r\n";
	}//end calendarData()

	/**
	 * Read the start time an event must have, or refuse to build one.
	 *
	 * Callers used to be free to omit `dtstart`, and the builder simply left
	 * the property out. The resulting VEVENT is invalid under RFC 5545 and, far
	 * worse, is beyond repair: CalDAV answers 500 to both DELETE and PUT on it,
	 * so no client can remove it or add the missing property afterwards.
	 *
	 * Refusing at write time is the only point where that is still fixable.
	 *
	 * @param array $data The event data handed to the builder.
	 *
	 * @return DateTime The parsed start time.
	 *
	 * @throws Exception When dtstart is absent or does not parse.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function requiredStart(array $data): DateTime {
		if (empty($data['dtstart']) === true) {
			throw new Exception(
				'Event start time (dtstart) is required: a calendar event without '
				. 'DTSTART cannot be displayed or deleted by any client.'
			);
		}

		try {
			return new DateTime((string)$data['dtstart']);
		} catch (\Throwable $malformed) {
			throw new Exception(
				'Event start time (dtstart) could not be read as a date: '
				. $malformed->getMessage()
			);
		}
	}//end requiredStart()

	/**
	 * The opening lines of a new event, up to and including DTSTART.
	 *
	 * @param string   $uid     The event UID.
	 * @param string   $summary The unescaped summary.
	 * @param DateTime $dtstart The start time.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function eventHeaderLines(string $uid, string $summary, DateTime $dtstart): array {
		return [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//OpenRegister//Events//EN',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . gmdate('Ymd\THis\Z'),
			'SUMMARY:' . $this->escapeIcalText(text: $summary),
			'DTSTART:' . $dtstart->format('Ymd\THis\Z'),
		];
	}//end eventHeaderLines()

	/**
	 * The optional DTEND, LOCATION, DESCRIPTION and ATTENDEE lines of a new event.
	 *
	 * @param array $data The event data.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function eventDetailLines(array $data): array {
		$lines = [];
		if (empty($data['dtend']) === false) {
			$lines[] = 'DTEND:' . (new DateTime($data['dtend']))->format('Ymd\THis\Z');
		}

		foreach (['LOCATION' => 'location', 'DESCRIPTION' => 'description'] as $property => $key) {
			if (empty($data[$key]) === false) {
				$lines[] = $property . ':' . $this->escapeIcalText(text: $data[$key]);
			}
		}

		return array_merge($lines, $this->attendeeLines(attendees: ($data['attendees'] ?? null)));
	}//end eventDetailLines()

	/**
	 * The ATTENDEE lines of a new event.
	 *
	 * @param mixed $attendees The attendee addresses; anything but an array adds none.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function attendeeLines(mixed $attendees): array {
		if (is_array($attendees) === false) {
			return [];
		}

		return array_map(
			fn ($attendee): string => 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:' . $attendee,
			array_values($attendees)
		);
	}//end attendeeLines()

	/**
	 * The X-OPENREGISTER-* properties and the RFC 9253 LINK of a new event.
	 *
	 * @param array $link The linked object: registerId, schemaId, objectUuid, objectTitle.
	 *
	 * @return list<string> The iCalendar lines.
	 */
	private function eventLinkLines(array $link): array {
		$linkUri = '/apps/openregister/api/objects/' . $link['registerId'] . '/' . $link['schemaId'] . '/' . $link['objectUuid'];

		return [
			'X-OPENREGISTER-REGISTER:' . $link['registerId'],
			'X-OPENREGISTER-SCHEMA:' . $link['schemaId'],
			'X-OPENREGISTER-OBJECT:' . $link['objectUuid'],
			'LINK;LINKREL="related";LABEL="' . $this->escapeIcalText(text: $link['objectTitle']) . '";VALUE=URI:' . $linkUri,
		];
	}//end eventLinkLines()

	/**
	 * Escape text for iCalendar property values.
	 *
	 * @param string $text The text to escape
	 *
	 * @return string The escaped text
	 */
	private function escapeIcalText(string $text): string {
		$text = str_replace('\\', '\\\\', $text);
		$text = str_replace("\n", '\\n', $text);
		$text = str_replace(',', '\\,', $text);
		$text = str_replace(';', '\;', $text);

		return $text;
	}//end escapeIcalText()
	/**
	 * The event array of a VEVENT.
	 *
	 * @param mixed  $vevent     The VEVENT component.
	 * @param string $calendarId The calendar ID.
	 * @param string $uri        The calendar object URI.
	 *
	 * @return array<string, mixed> The event.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function eventArray(mixed $vevent, string $calendarId, string $uri): array {
		return array_merge(
			[
				'id' => $uri,
				'uid' => $this->veventText(vevent: $vevent, property: 'UID'),
				'calendarId' => $calendarId,
			],
			$this->veventFields(vevent: $vevent),
			$this->extractOpenRegisterProperties(vevent: $vevent)
		);
	}//end eventArray()

	/**
	 * The descriptive fields of a VEVENT, in the order the event array lists them.
	 *
	 * @param mixed $vevent The VEVENT component.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function veventFields(mixed $vevent): array {
		return [
			'summary' => $this->veventText(vevent: $vevent, property: 'SUMMARY') ?? '',
			'dtstart' => $this->veventDate(vevent: $vevent, property: 'DTSTART'),
			'dtend' => $this->veventDate(vevent: $vevent, property: 'DTEND'),
			'location' => $this->veventText(vevent: $vevent, property: 'LOCATION'),
			'description' => $this->veventText(vevent: $vevent, property: 'DESCRIPTION') ?? '',
			'attendees' => $this->veventAttendees(vevent: $vevent),
			'status' => $this->veventStatus(vevent: $vevent),
		];
	}//end veventFields()

	/**
	 * The lower-cased STATUS of a VEVENT, or null when the event does not have one.
	 *
	 * @param mixed $vevent The VEVENT component.
	 *
	 * @return string|null The status.
	 */
	private function veventStatus(mixed $vevent): ?string {
		$status = $this->veventText(vevent: $vevent, property: 'STATUS');
		if ($status === null) {
			return null;
		}

		return strtolower($status);
	}//end veventStatus()

	/**
	 * The text of a VEVENT property, or null when the event does not have it.
	 *
	 * @param mixed  $vevent   The VEVENT component.
	 * @param string $property The property name.
	 *
	 * @return string|null The text.
	 */
	private function veventText(mixed $vevent, string $property): ?string {
		if (isset($vevent->$property) === false) {
			return null;
		}

		return (string)$vevent->$property;
	}//end veventText()

	/**
	 * A VEVENT date-time property as an ISO 8601 string, or null when the event does not have it.
	 *
	 * @param mixed  $vevent   The VEVENT component.
	 * @param string $property The property name, DTSTART or DTEND.
	 *
	 * @return string|null The date-time.
	 */
	private function veventDate(mixed $vevent, string $property): ?string {
		if (isset($vevent->$property) === false) {
			return null;
		}

		return $vevent->$property->getDateTime()->format('c');
	}//end veventDate()

	/**
	 * The attendee addresses of a VEVENT, without the mailto: prefix.
	 *
	 * @param mixed $vevent The VEVENT component.
	 *
	 * @return list<string> The addresses.
	 */
	private function veventAttendees(mixed $vevent): array {
		$attendees = [];
		if (isset($vevent->ATTENDEE) === true) {
			foreach ($vevent->ATTENDEE as $attendee) {
				$attendees[] = str_replace('mailto:', '', (string)$attendee);
			}
		}

		return $attendees;
	}//end veventAttendees()

	/**
	 * Extract X-OPENREGISTER-* properties from a VEVENT component.
	 *
	 * @param mixed $vevent The VEVENT component.
	 *
	 * @return array{objectUuid: string|null, registerId: int|null, schemaId: int|null}
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-webhookeventlistener-must-extract-structured-payloads-from-all-event-types
	 */
	private function extractOpenRegisterProperties(mixed $vevent): array {
		return [
			'objectUuid' => $this->veventText(vevent: $vevent, property: 'X-OPENREGISTER-OBJECT'),
			'registerId' => $this->veventInt(vevent: $vevent, property: 'X-OPENREGISTER-REGISTER'),
			'schemaId' => $this->veventInt(vevent: $vevent, property: 'X-OPENREGISTER-SCHEMA'),
		];
	}//end extractOpenRegisterProperties()

	/**
	 * A VEVENT property as an integer, or null when the event does not have it.
	 *
	 * @param mixed  $vevent   The VEVENT component.
	 * @param string $property The property name.
	 *
	 * @return int|null The value.
	 */
	private function veventInt(mixed $vevent, string $property): ?int {
		$text = $this->veventText(vevent: $vevent, property: $property);
		if ($text === null) {
			return null;
		}

		return (int)$text;
	}//end veventInt()
}//end class
