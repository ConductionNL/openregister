<?php

/**
 * One calendar object as a calendar-object event delivers it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\CalendarEvent;

/**
 * The calendar it sits on (id and uri), its own uri, and its raw iCalendar
 * text. Built from the event payload defensively: every key may be absent, and
 * the text may arrive as a stream.
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */
final class InboundCalendarObject {

	/**
	 * The property that tags a VEVENT as belonging to an OpenRegister object.
	 *
	 * @var string
	 */
	public const OBJECT_TAG = 'X-OPENREGISTER-OBJECT';

	/**
	 * Constructor.
	 *
	 * @param int    $calendarId   The calendar's numeric id.
	 * @param string $calendarUri  The calendar's uri ('' when unknown).
	 * @param string $eventUri     The calendar object's uri ('' when unknown).
	 * @param string $calendarText The calendar object's iCalendar text ('' when absent).
	 */
	public function __construct(
		public readonly int $calendarId,
		public readonly string $calendarUri,
		public readonly string $eventUri,
		public readonly string $calendarText,
	) {
	}//end __construct()

	/**
	 * Build from the three payload parts every calendar-object event carries.
	 *
	 * @param int                  $calendarId   The calendar id.
	 * @param array<string, mixed> $calendarData The calendar row (its `uri`).
	 * @param array<string, mixed> $objectData   The object row (`uri`, `calendardata`).
	 *
	 * @return self
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public static function fromEventPayload(int $calendarId, array $calendarData, array $objectData): self {
		$text = ($objectData['calendardata'] ?? '');
		if (is_resource($text) === true) {
			$text = stream_get_contents($text);
		}

		if (is_string($text) === false) {
			$text = '';
		}

		return new self(
			calendarId: $calendarId,
			calendarUri: (string) ($calendarData['uri'] ?? ''),
			eventUri: (string) ($objectData['uri'] ?? ''),
			calendarText: $text
		);
	}//end fromEventPayload()

	/**
	 * Whether the text carries the OpenRegister object tag (a string check, no parse).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
	 */
	public function isTagged(): bool {
		return stripos($this->calendarText, self::OBJECT_TAG) !== false;
	}//end isTagged()

	/**
	 * The object's UID, read from the raw text without a full parse, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-reverse-event-to-objects-lookup-req-cal-inb-002
	 */
	public function uid(): ?string {
		if (preg_match('/^UID(?:;[^:\r\n]*)?:([^\r\n]*)/mi', $this->calendarText, $match) !== 1) {
			return null;
		}

		$uid = trim($match[1]);
		if ($uid === '') {
			return null;
		}

		return $uid;
	}//end uid()
}//end class
