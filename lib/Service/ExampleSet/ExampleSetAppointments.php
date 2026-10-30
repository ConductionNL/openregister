<?php

/**
 * OpenRegister Example Set Appointments
 *
 * The appointments an example set puts on its objects: a fragment's
 * `appointments` part names the object, the account whose calendar holds the
 * appointment, its summary, start, end, place and the people invited. The
 * event is written to that calendar and linked to the object as a linked
 * appointment is; remove deletes the event and the link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Party\PartyService;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the appointments of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
 */
class ExampleSetAppointments extends ExampleSetEngineRows {
	/**
	 * The appointment fields a fragment may set.
	 */
	public const FIELDS = ['object', 'owner', 'calendar', 'summary', 'start', 'end', 'location', 'description', 'attendees'];

	/**
	 * The DAV app's CalDAV backend, by name: the DAV app is not part of OCP.
	 */
	private const CALDAV = 'OCA\\DAV\\CalDAV\\CalDavBackend';

	/**
	 * The participation states an invitee may have, by the word a fragment uses.
	 */
	public const STATES = ['invited' => 'NEEDS-ACTION', 'accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentative' => 'TENTATIVE'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the CalDAV backend, the link mapper and the object finder on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part appointments live in.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function part(): string {
		return 'appointments';
	}//end part()

	/**
	 * The word for one appointment, in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function noun(): string {
		return 'appointment';
	}//end noun()

	/**
	 * Why a filled appointment cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled appointment fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not an appointment field a set may write';
		}

		$required = [
			'object'  => 'the object it is on',
			'owner'   => 'the account whose calendar holds it',
			'summary' => 'a summary',
			'start'   => 'a start',
		];
		foreach ($required as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'an appointment needs '.$what.' ('.$field.')';
			}
		}

		if (in_array((string) $data['owner'], $users, true) === false) {
			return 'owner "'.(string) $data['owner'].'" is not an account the set creates or adopts';
		}

		$dates = $this->datesRefusal(data: $data);
		if ($dates !== '') {
			return $dates;
		}

		return $this->attendeesRefusal(attendees: ($data['attendees'] ?? []));
	}//end refusal()

	/**
	 * Write the event to the owner's calendar and link it to the object.
	 *
	 * @param string               $app  The app that ships the fragment, recorded as the linker.
	 * @param string               $key  The appointment's key in the fragment (unused).
	 * @param array<string, mixed> $data The filled appointment fields.
	 *
	 * @return array{error: string, entry: array{object: string, calendar: int, uri: string, uid: string}|null} The entry, or why not.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$objectUuid = (string) $data['object'];
		$target     = $this->target(uuid: $objectUuid);
		if ($target === null) {
			return ['error' => 'no object or task "'.$objectUuid.'" to put the appointment on', 'entry' => null];
		}

		try {
			$calendarUri = (string) ($data['calendar'] ?? 'personal');
			$calendarId  = $this->calendarId(owner: (string) $data['owner'], uri: $calendarUri);
			$uid         = strtoupper(bin2hex(random_bytes(16)));
			$uri         = $uid.'.ics';
			$this->caldav()->createCalendarObject($calendarId, $uri, $this->ics(uid: $uid, data: $data));

			$link = new CalendarLink();
			$link->setObjectUuid($objectUuid);
			$link->setRegisterId($target['register']);
			$link->setSchemaId($target['schema']);
			$link->setCalendarUri($calendarUri);
			$link->setCalendarId($calendarId);
			$link->setEventUid($uid);
			$link->setEventUri($uri);
			$link->setSummary((string) $data['summary']);
			// The link table holds wall time without a zone and is read back as UTC.
			$link->setDtstart((new DateTime((string) $data['start']))->setTimezone(new DateTimeZone('UTC')));
			$link->setDtend($this->dateOrNull(value: ($data['end'] ?? null))?->setTimezone(new DateTimeZone('UTC')));
			$link->setLocation($this->textOrNull(value: ($data['location'] ?? null)));
			$link->setLinkedBy($app);
			$link->setLinkedAt(new DateTime());
			$this->container->get(CalendarLinkMapper::class)->insert($link);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => ['object' => $objectUuid, 'calendar' => $calendarId, 'uri' => $uri, 'uid' => $uid]];
	}//end write()

	/**
	 * The register and schema an appointment's link names: those of the object, or, for a task (which is no object),
	 * those of the record the task is on; null when the uuid names neither.
	 *
	 * @param string $uuid The object or task uuid.
	 *
	 * @return array{register: int, schema: int}|null
	 */
	private function target(string $uuid): ?array {
		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: $uuid);
		if ($object !== null) {
			return ['register' => (int) $object->getRegister(), 'schema' => (int) $object->getSchema()];
		}

		try {
			$task = $this->container->get(TaskMapper::class)->findByUuid($uuid);
		} catch (Throwable) {
			return null;
		}

		return ['register' => (int) $task->getRegisterId(), 'schema' => (int) $task->getSchemaId()];
	}//end target()

	/**
	 * Whether a written appointment is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			return $this->caldav()->getCalendarObject((int) $entry['calendar'], (string) $entry['uri']) === null;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Delete the event and its link.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-appointments-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$links = $this->container->get(CalendarLinkMapper::class);
			$links->deleteByObjectAndEvent(objectUuid: (string) $entry['object'], eventUid: (string) $entry['uid']);
			if ($this->lost(entry: $entry) === false) {
				$this->caldav()->deleteCalendarObject((int) $entry['calendar'], (string) $entry['uri']);
			}
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * The id of the owner's calendar with this uri, written when the account has none yet.
	 *
	 * @param string $owner The account.
	 * @param string $uri   The calendar uri.
	 *
	 * @return int
	 */
	private function calendarId(string $owner, string $uri): int {
		$principal = 'principals/users/'.$owner;
		foreach ($this->caldav()->getCalendarsForUser($principal) as $calendar) {
			if (($calendar['uri'] ?? null) === $uri) {
				return (int) $calendar['id'];
			}
		}

		return (int) $this->caldav()->createCalendar($principal, $uri, ['{DAV:}displayname' => ucfirst($uri)]);
	}//end calendarId()

	/**
	 * The event as iCalendar text, its invitees with their participation.
	 *
	 * @param string               $uid  The event's uid.
	 * @param array<string, mixed> $data The filled appointment fields.
	 *
	 * @return string
	 */
	private function ics(string $uid, array $data): string {
		$utc   = new DateTimeZone('UTC');
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//OpenRegister//Example set//EN',
			'BEGIN:VEVENT',
			'UID:'.$uid,
			'DTSTAMP:'.gmdate('Ymd\THis\Z'),
			'SUMMARY:'.$this->escape(text: (string) $data['summary']),
			'DTSTART:'.(new DateTime((string) $data['start']))->setTimezone($utc)->format('Ymd\THis\Z'),
		];
		$end = $this->dateOrNull(value: ($data['end'] ?? null));
		if ($end !== null) {
			$lines[] = 'DTEND:'.$end->setTimezone($utc)->format('Ymd\THis\Z');
		}

		foreach (['location' => 'LOCATION', 'description' => 'DESCRIPTION'] as $field => $property) {
			if ($this->textOrNull(value: ($data[$field] ?? null)) !== null) {
				$lines[] = $property.':'.$this->escape(text: (string) $data[$field]);
			}
		}

		$owner = $this->container->get(IUserManager::class)->get((string) $data['owner']);
		$email = (string) $owner?->getEMailAddress();
		if ($email !== '') {
			$lines[] = 'ORGANIZER;CN="'.$this->escape(text: (string) $owner?->getDisplayName()).'":mailto:'.$email;
		}

		foreach ((array) ($data['attendees'] ?? []) as $attendee) {
			$attendee = (array) $attendee;
			$state    = self::STATES[(string) ($attendee['status'] ?? 'invited')];
			$name     = $this->escape(text: (string) ($attendee['name'] ?? $attendee['email']));
			$lines[]  = 'ATTENDEE;CN="'.$name.'";ROLE=REQ-PARTICIPANT;PARTSTAT='.$state.':mailto:'.(string) $attendee['email'];
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode("\r\n", $lines)."\r\n";
	}//end ics()

	/**
	 * A start or end that is not a date.
	 *
	 * @param array<string, mixed> $data The filled appointment fields.
	 *
	 * @return string
	 */
	private function datesRefusal(array $data): string {
		foreach (['start', 'end'] as $field) {
			try {
				$this->dateOrNull(value: ($data[$field] ?? null));
			} catch (Throwable) {
				return $field.' "'.(string) $data[$field].'" is not a date';
			}
		}

		return '';
	}//end datesRefusal()

	/**
	 * An invitee without an address, or with a participation the set does not know.
	 *
	 * @param mixed $attendees The declared invitees.
	 *
	 * @return string
	 */
	private function attendeesRefusal(mixed $attendees): string {
		foreach ((array) $attendees as $attendee) {
			$attendee = (array) $attendee;
			if (filter_var(($attendee['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
				return 'an invitee needs an e-mail address (email)';
			}

			if (isset(self::STATES[(string) ($attendee['status'] ?? 'invited')]) === false) {
				return 'an invitee\'s status is one of '.implode(', ', array_keys(self::STATES));
			}
		}

		return '';
	}//end attendeesRefusal()

	/**
	 * A date from a filled field, or null when the field is empty.
	 *
	 * @param mixed $value The field.
	 *
	 * @return DateTime|null
	 */
	private function dateOrNull(mixed $value): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		return new DateTime((string) $value);
	}//end dateOrNull()

	/**
	 * A non-empty text, or null.
	 *
	 * @param mixed $value The field.
	 *
	 * @return string|null
	 */
	private function textOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return $value;
	}//end textOrNull()

	/**
	 * Text escaped for an iCalendar property value.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
	}//end escape()

	/**
	 * The CalDAV backend (`OCA\DAV\CalDAV\CalDavBackend`).
	 *
	 * @return mixed
	 */
	private function caldav(): mixed {
		return $this->container->get(self::CALDAV);
	}//end caldav()
}//end class
