<?php

/**
 * Turns one declared date on one object into a VEVENT.
 *
 * The kind decides the shape, and nothing else does:
 *
 *  - a `deadline` is an all-day event on the day the term engine resolves,
 *    carrying the alarm the schema declares;
 *  - an `appointment` is a timed event with a duration and, when the schema
 *    says where its attendees live, one ATTENDEE line per invitee carrying
 *    the answer recorded on the record;
 *  - a `period` spans a start and an end.
 *
 * The UID is derived from the schema, the object and the property, so it is
 * the same on every read. A client that re-subscribes updates the event it
 * already has instead of collecting a second copy of it, and a date that
 * moves moves the event rather than leaving a ghost behind.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;
use Throwable;

/**
 * Builds the iCalendar lines of one object date.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One VEVENT is one output
 * shape, and its parts are the branches: three date kinds, all-day against
 * timed, attendees, alarms. Splitting the class would spread the shape of a
 * single iCalendar component over several files, where a change to it can be
 * made in one of them and missed in the rest.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class ObjectDateEventBuilder {

	/**
	 * A value that is a calendar date and carries no time of day.
	 *
	 * @var string
	 */
	private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

	/**
	 * The iCalendar text writer.
	 *
	 * @var IcalendarWriter
	 */
	private readonly IcalendarWriter $writer;

	/**
	 * Constructor.
	 *
	 * @param DeadlineDateResolver $deadlines The term-engine date resolver.
	 * @param AppointmentAttendeeService $attendees The attendee-response store.
	 */
	public function __construct(
		private readonly DeadlineDateResolver $deadlines,
		private readonly AppointmentAttendeeService $attendees,
	) {
		$this->writer = new IcalendarWriter();
	}//end __construct()

	/**
	 * Build the VEVENT for one declared date, or null when it publishes nothing.
	 *
	 * @param ObjectDateOccurrence $occurrence The object, schema, declaration and tenant zone.
	 *
	 * @return array{lines: array<int, string>, years: array<int, int>}|null
	 *         The unfolded lines and the years the event touches, or null.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function build(ObjectDateOccurrence $occurrence): ?array {
		$data = $occurrence->object->getObject();
		$start = $this->startMoment(occurrence: $occurrence, data: $data);
		if ($start === null) {
			return null;
		}

		$start = $start->setTimezone($occurrence->timeZone);
		$span = new EventSpan(
			start: $start,
			end: $this->endMoment(occurrence: $occurrence, start: $start),
			allDay: $this->isAllDay(occurrence: $occurrence, data: $data)
		);
		$lines = array_merge(
			$this->headerLines(occurrence: $occurrence, data: $data),
			$this->timingLines(span: $span, timeZone: $occurrence->timeZone),
			$this->bodyLines(occurrence: $occurrence),
			$this->attendeeLines(occurrence: $occurrence, data: $data),
			$this->alarmLines(declaration: $occurrence->declaration)
		);

		$lines[] = 'END:VEVENT';

		return [
			'lines' => $lines,
			'years' => [(int)$span->start->format('Y'), (int)$span->end->format('Y')],
		];
	}//end build()

	/**
	 * The start the event publishes on: the stored value, or the term engine's date for a deadline.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return DateTimeImmutable|null The start, or null when the date publishes nothing.
	 */
	private function startMoment(ObjectDateOccurrence $occurrence, array $data): ?DateTimeImmutable {
		$declaration = $occurrence->declaration;
		$start = $this->parse(value: ($data[$declaration->property] ?? null), timeZone: $occurrence->timeZone);
		if ($declaration->kind !== ObjectDateDeclaration::KIND_DEADLINE) {
			return $start;
		}

		return $this->deadlines->resolve(
			lookup: new DeadlineLookup(
				objectUuid: (string)$occurrence->object->getUuid(),
				declaration: $declaration,
				rawDate: $start,
				organisation: $occurrence->object->getOrganisation()
			)
		);
	}//end startMoment()

	/**
	 * The BEGIN, UID, DTSTAMP and SUMMARY lines.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return array<int, string> The lines.
	 */
	private function headerLines(ObjectDateOccurrence $occurrence, array $data): array {
		return [
			'BEGIN:VEVENT',
			'UID:' . $this->uid(occurrence: $occurrence),
			'DTSTAMP:' . $this->stamp(object: $occurrence->object),
			'SUMMARY:' . $this->writer->escapeText(value: $this->summary(occurrence: $occurrence, data: $data)),
		];
	}//end headerLines()

	/**
	 * The CATEGORIES, STATUS, TRANSP and URL lines.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 *
	 * @return array<int, string> The lines.
	 */
	private function bodyLines(ObjectDateOccurrence $occurrence): array {
		return [
			'CATEGORIES:OpenRegister,' . strtoupper($occurrence->declaration->kind),
			'STATUS:CONFIRMED',
			'TRANSP:TRANSPARENT',
			'URL:' . $this->objectUrl(object: $occurrence->object, schemaId: $occurrence->schemaId),
		];
	}//end bodyLines()

	/**
	 * The stable UID for one object date.
	 *
	 * @param ObjectDateOccurrence $occurrence The schema, object and declaration.
	 *
	 * @return string The UID.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function uid(ObjectDateOccurrence $occurrence): string {
		$property = preg_replace('/[^A-Za-z0-9_-]/', '-', $occurrence->declaration->property);

		return sprintf(
			'openregister-%s-%s-%s@openregister.app',
			(string)$occurrence->schemaId,
			(string)$occurrence->object->getUuid(),
			(string)$property
		);
	}//end uid()

	/**
	 * Parse a stored date value into an instant, or null when it is not one.
	 *
	 * @param mixed $value The stored value.
	 * @param DateTimeZone $timeZone The zone a bare date is read in.
	 *
	 * @return DateTimeImmutable|null The instant, or null.
	 */
	private function parse(mixed $value, DateTimeZone $timeZone): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(trim($value), $timeZone);
		} catch (Throwable $unparseable) {
			return null;
		}
	}//end parse()

	/**
	 * The moment the event ends.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param DateTimeImmutable $start The resolved start.
	 *
	 * @return DateTimeImmutable The end.
	 */
	private function endMoment(ObjectDateOccurrence $occurrence, DateTimeImmutable $start): DateTimeImmutable {
		$end = $this->declaredEnd(occurrence: $occurrence, start: $start);
		if ($end !== null) {
			return $end;
		}

		if ($occurrence->declaration->kind === ObjectDateDeclaration::KIND_APPOINTMENT) {
			return $this->appointmentEnd(declaration: $occurrence->declaration, start: $start);
		}

		return $start;
	}//end endMoment()

	/**
	 * The end the object itself carries, when it declares one at or after the start.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param DateTimeImmutable $start The resolved start.
	 *
	 * @return DateTimeImmutable|null The end in the tenant zone, or null when there is none to use.
	 */
	private function declaredEnd(ObjectDateOccurrence $occurrence, DateTimeImmutable $start): ?DateTimeImmutable {
		$endProperty = $occurrence->declaration->endProperty;
		if ($endProperty === null) {
			return null;
		}

		$data = $occurrence->object->getObject();
		$end = $this->parse(value: ($data[$endProperty] ?? null), timeZone: $occurrence->timeZone);
		if ($end !== null && $end >= $start) {
			return $end->setTimezone($occurrence->timeZone);
		}

		return null;
	}//end declaredEnd()

	/**
	 * The end of an appointment from its declared length, or the default hour.
	 *
	 * @param ObjectDateDeclaration $declaration The declaration.
	 * @param DateTimeImmutable $start The resolved start.
	 *
	 * @return DateTimeImmutable The end.
	 */
	private function appointmentEnd(ObjectDateDeclaration $declaration, DateTimeImmutable $start): DateTimeImmutable {
		$minutes = ($declaration->durationMinutes ?? ObjectDateDeclaration::DEFAULT_APPOINTMENT_MINUTES);
		if ($minutes < 1) {
			$minutes = ObjectDateDeclaration::DEFAULT_APPOINTMENT_MINUTES;
		}

		// `add()` over `modify()`: modify() answers false on a modifier it
		// cannot read, and an appointment that silently loses its end is
		// worse than one that throws.
		return $start->add(new DateInterval('PT' . $minutes . 'M'));
	}//end appointmentEnd()

	/**
	 * Whether the event publishes as an all-day event.
	 *
	 * A deadline always does: that is what the driven passers publish and what
	 * survives a time-zone change. A period does when both of its values are
	 * calendar dates with no time of day. An appointment never does.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return bool True when the event is all-day.
	 */
	private function isAllDay(ObjectDateOccurrence $occurrence, array $data): bool {
		$declaration = $occurrence->declaration;
		if ($declaration->kind === ObjectDateDeclaration::KIND_DEADLINE) {
			return true;
		}

		if ($declaration->kind === ObjectDateDeclaration::KIND_APPOINTMENT) {
			return false;
		}

		$raw = ($data[$declaration->property] ?? null);
		$end = null;
		if ($declaration->endProperty !== null) {
			$end = ($data[$declaration->endProperty] ?? null);
		}

		return ($this->isDateOnly(value: $raw) === true && $this->isDateOnly(value: $end) === true);
	}//end isAllDay()

	/**
	 * Whether a stored value is a calendar date with no time of day.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return bool True when the value is date-only.
	 */
	private function isDateOnly(mixed $value): bool {
		return (is_string($value) === true && preg_match(self::DATE_ONLY, trim($value)) === 1);
	}//end isDateOnly()

	/**
	 * The DTSTART and DTEND lines.
	 *
	 * An all-day event uses VALUE=DATE with an exclusive end, per RFC 5545. A
	 * timed event carries the tenant TZID, never a naive local time: the
	 * calendar's VTIMEZONE resolves it for a client in another zone.
	 *
	 * @param EventSpan $span The start, end and all-day flag.
	 * @param DateTimeZone $timeZone The tenant zone.
	 *
	 * @return array<int, string> The lines.
	 */
	private function timingLines(EventSpan $span, DateTimeZone $timeZone): array {
		$start = $span->start;
		$end = $span->end;
		if ($span->allDay === true) {
			// DTEND is exclusive, so a one-day event ends on the next day.
			$exclusive = $end->modify('+1 day');
			return [
				'DTSTART;VALUE=DATE:' . $start->format('Ymd'),
				'DTEND;VALUE=DATE:' . $exclusive->format('Ymd'),
			];
		}

		$tzid = $timeZone->getName();

		return [
			'DTSTART;TZID=' . $tzid . ':' . $start->format('Ymd\THis'),
			'DTEND;TZID=' . $tzid . ':' . $end->format('Ymd\THis'),
		];
	}//end timingLines()

	/**
	 * The DTSTAMP value: when the record last changed.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string The UTC stamp.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is the PHP conversion; there is no DI alternative.
	 */
	private function stamp(ObjectEntity $object): string {
		$updated = $object->getUpdated();

		$moment = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($updated !== null) {
			$moment = DateTimeImmutable::createFromInterface($updated);
		}


		return $moment->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}//end stamp()

	/**
	 * The event summary.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return string The summary.
	 */
	private function summary(ObjectDateOccurrence $occurrence, array $data): string {
		$declaration = $occurrence->declaration;
		if ($declaration->summaryTemplate !== null) {
			return $this->interpolate(template: $declaration->summaryTemplate, data: $data);
		}

		foreach (['title', 'name', 'naam', 'omschrijving'] as $candidate) {
			$value = ($data[$candidate] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return (string)$occurrence->object->getUuid();
	}//end summary()

	/**
	 * Replace `{{property}}` placeholders with the object's own values.
	 *
	 * @param string $template The template.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return string The interpolated text.
	 */
	private function interpolate(string $template, array $data): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/',
			static function (array $match) use ($data): string {
				$value = ($data[$match[1]] ?? '');

				if (is_scalar($value) === true) {
					return (string)$value;
				}

				return '';
			},
			$template
		);
	}//end interpolate()

	/**
	 * The deep link back to the object in OpenRegister.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int|string $schemaId The schema.
	 *
	 * @return string The URL path.
	 */
	private function objectUrl(ObjectEntity $object, int|string $schemaId): string {
		return sprintf(
			'/apps/openregister/objects/%s/%s/%s',
			(string)$object->getRegister(),
			(string)$schemaId,
			(string)$object->getUuid()
		);
	}//end objectUrl()

	/**
	 * One ATTENDEE line per invitee, carrying the answer on the record.
	 *
	 * @param ObjectDateOccurrence $occurrence The occurrence.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return array<int, string> The lines.
	 */
	private function attendeeLines(ObjectDateOccurrence $occurrence, array $data): array {
		$declaration = $occurrence->declaration;
		$invitees = $this->invitees(declaration: $declaration, data: $data);
		if ($invitees === null) {
			return [];
		}

		$answers = $this->attendees->responsesByAttendee(object: $occurrence->object);
		$lines = [];

		foreach ($invitees as $invitee) {
			$name = $this->inviteeName(invitee: $invitee);
			if ($name === null) {
				continue;
			}

			$status = ($answers[$name]['status'] ?? AppointmentAttendeeService::STATUS_NEEDS_ACTION);
			$lines[] = 'ATTENDEE;PARTSTAT=' . $status . ':' . $this->attendeeAddress(name: $name);
		}

		return $lines;
	}//end attendeeLines()

	/**
	 * The stored invitee list of an appointment that declares one.
	 *
	 * @param ObjectDateDeclaration $declaration The declaration.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return array<mixed>|null The invitees, or null when the event carries no attendees.
	 */
	private function invitees(ObjectDateDeclaration $declaration, array $data): ?array {
		if ($declaration->kind !== ObjectDateDeclaration::KIND_APPOINTMENT
			|| $declaration->attendeesProperty === null
		) {
			return null;
		}

		$invitees = ($data[$declaration->attendeesProperty] ?? null);
		if (is_array($invitees) === false) {
			return null;
		}

		return $invitees;
	}//end invitees()

	/**
	 * The identifier of one invitee, whatever shape the schema stores it in.
	 *
	 * @param mixed $invitee The stored invitee.
	 *
	 * @return string|null The identifier, or null when unreadable.
	 */
	private function inviteeName(mixed $invitee): ?string {
		$name = $this->nonBlank(value: $invitee);
		if ($name !== null) {
			return $name;
		}

		if (is_array($invitee) === false) {
			return null;
		}

		return $this->firstNonBlank(invitee: $invitee);
	}//end inviteeName()

	/**
	 * The first identifying field of an invitee object that is filled in.
	 *
	 * @param array<mixed> $invitee The stored invitee object.
	 *
	 * @return string|null The identifier, or null when none is filled in.
	 */
	private function firstNonBlank(array $invitee): ?string {
		foreach (['email', 'uid', 'id', 'name'] as $key) {
			$value = $this->nonBlank(value: ($invitee[$key] ?? null));
			if ($value !== null) {
				return $value;
			}
		}

		return null;
	}//end firstNonBlank()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null.
	 */
	private function nonBlank(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return null;
	}//end nonBlank()

	/**
	 * The CAL-ADDRESS for an invitee identifier.
	 *
	 * @param string $name The identifier.
	 *
	 * @return string The address.
	 */
	private function attendeeAddress(string $name): string {
		if (str_contains($name, '@') === true) {
			return 'mailto:' . $name;
		}

		return 'urn:openregister:attendee:' . rawurlencode($name);
	}//end attendeeAddress()

	/**
	 * The VALARM a deadline declares, when it declares one.
	 *
	 * @param ObjectDateDeclaration $declaration The declaration.
	 *
	 * @return array<int, string> The lines.
	 */
	private function alarmLines(ObjectDateDeclaration $declaration): array {
		if ($declaration->alarmOffsetDays === null) {
			return [];
		}

		$trigger = '-P' . $declaration->alarmOffsetDays . 'D';
		if ($declaration->alarmOffsetDays === 0) {
			$trigger = '-PT0S';
		}


		return [
			'BEGIN:VALARM',
			'ACTION:DISPLAY',
			'TRIGGER;RELATED=START:' . $trigger,
			'DESCRIPTION:' . $this->writer->escapeText(value: $declaration->property),
			'END:VALARM',
		];
	}//end alarmLines()
}//end class
