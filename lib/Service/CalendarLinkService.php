<?php

/**
 * CalendarLinkService
 *
 * Tier-2 link-table service for the calendar integration leaf. Composes
 * the legacy {@see CalendarEventService} (X-OPENREGISTER-* CalDAV
 * properties) with the new {@see CalendarLinkMapper} (oc_openregister_calendar_links
 * link table). Reads UNION both sources and dedupes by (calendarUri, eventUid);
 * creates write both shapes; link-existing writes ONLY the link-table row
 * because the VEVENT may not be ours to mutate.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/integration-calendar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use DateTimeInterface;
use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Wraps CalendarEventService and CalendarLinkMapper.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) CalDAV protocol requires handling multiple
 *   branching cases per operation (object types, URI resolution, legacy X-OR fallback) — the
 *   complexity is inherent to wrapping two distinct CalDAV data sources.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   CalDAV link service must compose CalDavBackend,
 *   CalendarEventService, CalendarLinkMapper, UserSession, and Logger — each serves a distinct
 *   concern and cannot be merged.
 * @SuppressWarnings(PHPMD.StaticAccess)             Sabre\VObject\Reader::read() is a static factory with
 *   no injectable alternative in the Sabre VObject library.
 */
class CalendarLinkService implements CalendarLinkServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param CalendarLinkMapper $linkMapper Link-table mapper.
	 * @param CalendarEventService $calendarEventService Legacy CalDAV-property service.
	 * @param CalDavBackend $calDavBackend CalDAV backend for direct picker queries.
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalendarLinkMapper $linkMapper,
		private readonly CalendarEventService $calendarEventService,
		private readonly CalDavBackend $calDavBackend,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Link an existing CalDAV event to an OR object.
	 *
	 * Writes ONLY a link-table row (not the X-OR-* properties — we do
	 * not own the VEVENT). Re-fetches event details from CalDAV to
	 * populate cached fields (summary/dtstart/dtend/location).
	 *
	 * @param string $objectUuid Object UUID.
	 * @param int $registerId Register id.
	 * @param int $schemaId Schema id.
	 * @param string $calendarUri Calendar URI (per-user CalDAV path component).
	 * @param string $eventUid VEVENT UID.
	 *
	 * @return CalendarLink The persisted link row.
	 *
	 * @throws Exception When no user is logged in or the event cannot be located.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkEvent(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		string $calendarUri,
		string $eventUid,
	): CalendarLink {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		// Locate the VEVENT on the named calendar so we can cache summary/dtstart/etc.
		$located = $this->locateEventOnCalendar(
			userId: $user->getUID(),
			calendarUri: $calendarUri,
			eventUid: $eventUid
		);

		if ($located === null) {
			throw new Exception('Calendar event ' . $eventUid . ' not found on calendar ' . $calendarUri);
		}

		$link = new CalendarLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		$link->setCalendarUri($calendarUri);
		$link->setCalendarId((int)$located['calendarId']);
		$link->setEventUid($eventUid);
		$link->setEventUri((string)$located['eventUri']);
		$link->setSummary($located['summary']);
		$link->setDtstart($located['dtstart']);
		$link->setDtend($located['dtend']);
		$link->setLocation($located['location']);
		$link->setLinkedBy($user->getUID());
		$link->setLinkedAt(new DateTime());
		$link->setTaggedWithXor(false);

		return $this->linkMapper->insert(entity: $link);
	}//end linkEvent()

	/**
	 * Create a new VEVENT through CalendarEventService and record a link-table row.
	 *
	 * Delegates the VEVENT write (which adds X-OPENREGISTER-* properties)
	 * to {@see CalendarEventService::createEvent()} then mirrors the
	 * linkage in the link table with `taggedWithXor=true`.
	 *
	 * @param string $objectUuid Object UUID.
	 * @param int $registerId Register id.
	 * @param int $schemaId Schema id.
	 * @param array<string,mixed> $eventData Event data (summary, dtstart, dtend, location, ...).
	 *
	 * @return CalendarLink The persisted link row.
	 *
	 * @throws Exception On creation failure.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createAndLinkEvent(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		array $eventData,
	): CalendarLink {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$objectTitle = (string)($eventData['objectTitle'] ?? $objectUuid);

		$event = $this->calendarEventService->createEvent(
			registerId: $registerId,
			schemaId: $schemaId,
			objectUuid: $objectUuid,
			objectTitle: $objectTitle,
			data: $eventData
		);

		if ($event === null) {
			throw new Exception('Failed to create calendar event');
		}

		// CalendarEventService::createEvent returns the veventToArray
		// shape with id=eventUri and calendarId stringified.
		$calendarId = (int)($event['calendarId'] ?? 0);
		$calendarUri = $this->resolveCalendarUriForId(userId: $user->getUID(), calendarId: $calendarId);

		$link = new CalendarLink();
		$link->setObjectUuid($objectUuid);
		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		$link->setCalendarUri($calendarUri ?? '');
		$link->setCalendarId($calendarId);
		$link->setEventUid((string)($event['uid'] ?? ''));
		$link->setEventUri((string)($event['id'] ?? ''));
		$link->setSummary((string)($event['summary'] ?? ''));
		$link->setDtstart($this->parseEventDateTime(value: $event['dtstart'] ?? null));
		$link->setDtend($this->parseEventDateTime(value: $event['dtend'] ?? null));
		$location = null;
		if (isset($event['location']) === true) {
			$location = (string)$event['location'];
		}

		$link->setLocation($location);
		$link->setLinkedBy($user->getUID());
		$link->setLinkedAt(new DateTime());
		$link->setTaggedWithXor(true);

		return $this->linkMapper->insert(entity: $link);
	}//end createAndLinkEvent()

	/**
	 * Parse a possibly-null event date/time value into a DateTime object.
	 *
	 * Returns null when the value is absent or cannot be parsed.
	 *
	 * @param mixed $value Raw date/time string or null from the event array.
	 *
	 * @return DateTime|null
	 */
	private function parseEventDateTime(mixed $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		try {
			return new DateTime((string)$value);
		} catch (Exception $e) {
			return null;
		}
	}//end parseEventDateTime()

	/**
	 * Unlink an event from an object.
	 *
	 * Always removes the link-table row. If the row carried
	 * `taggedWithXor=true`, additionally strips the X-OPENREGISTER-*
	 * properties from the underlying VEVENT (best-effort; failures
	 * are logged but do not abort the unlink).
	 *
	 * @param string $objectUuid Object UUID.
	 * @param string $eventUid VEVENT UID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkEvent(string $objectUuid, string $eventUid): void {
		$taggedWithXor = false;
		$calendarId = null;
		$eventUri = null;

		try {
			$existing = $this->linkMapper->findByObjectAndEvent(
				objectUuid: $objectUuid,
				eventUid: $eventUid
			);
			$taggedWithXor = (bool)$existing->getTaggedWithXor();
			$calendarId = $existing->getCalendarId();
			$eventUri = $existing->getEventUri();
		} catch (DoesNotExistException $e) {
			// No link-table row — this is fine. We may still need to
			// unlink an X-OR-only event below.
		}

		$this->linkMapper->deleteByObjectAndEvent(objectUuid: $objectUuid, eventUid: $eventUid);

		// If the row had matching X-OR-* tags, strip them.
		if ($taggedWithXor === true && $calendarId !== null && $eventUri !== null) {
			try {
				$this->calendarEventService->unlinkEvent(
					calendarId: (string)$calendarId,
					eventUri: $eventUri
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Failed to strip X-OPENREGISTER properties for event ' . $eventUid . ': ' . $e->getMessage()
				);
			}

			return;
		}

		// Fall back to the legacy X-OR-* scan: maybe this event
		// pre-dates the link table and only carries the custom
		// properties. Walk the user's events to find a match.
		try {
			$events = $this->calendarEventService->getEventsForObject(objectUuid: $objectUuid);
			foreach ($events as $event) {
				if (($event['uid'] ?? null) === $eventUid) {
					$this->calendarEventService->unlinkEvent(
						calendarId: (string)$event['calendarId'],
						eventUri: (string)$event['id']
					);
					break;
				}
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'Legacy X-OR unlink fallback failed for event ' . $eventUid . ': ' . $e->getMessage()
			);
		}
	}//end unlinkEvent()

	/**
	 * Get all events linked to an object as the UNION of link-table rows
	 * and the legacy X-OPENREGISTER-* CalDAV scan.
	 *
	 * Each row carries a `source` field:
	 *   - `link-table`  — only in the link table
	 *   - `xor-only`    — only on the VEVENT as X-OR-* properties
	 *   - `both`        — present in both sources
	 *
	 * Dedupe key: `(calendarUri, eventUid)` where calendarUri can be
	 * empty when the CalDAV scan does not surface it; in that case
	 * dedupe falls back to eventUid alone.
	 *
	 * @param string $objectUuid Object UUID.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedEvents(string $objectUuid): array {
		$merged = [];

		// 1. Link-table rows
		foreach ($this->linkMapper->findByObjectUuid(objectUuid: $objectUuid) as $link) {
			$key = $link->getCalendarUri() . '|' . $link->getEventUid();
			$payload = $link->jsonSerialize();
			$payload['source'] = 'link-table';
			$payload['attendees'] = $this->attendeesOf(link: $link);
			$merged[$key] = $payload;
		}

		// 2. Legacy X-OR-* scan (best-effort; degrade to empty list)
		try {
			$xorEvents = $this->calendarEventService->getEventsForObject(objectUuid: $objectUuid);
		} catch (Throwable $e) {
			$this->logger->info(
				'X-OR-* scan failed during getLinkedEvents: ' . $e->getMessage()
			);
			$xorEvents = [];
		}

		$userId = $this->userSession->getUser()?->getUID();
		$calendarMap = [];
		if ($userId !== null) {
			$calendarMap = $this->buildCalendarMapForUser(userId: $userId);
		}

		foreach ($xorEvents as $event) {
			$eventUid = (string)($event['uid'] ?? '');
			if ($eventUid === '') {
				continue;
			}

			$calendarId = (int)($event['calendarId'] ?? 0);
			$calendarUri = $calendarMap[$calendarId] ?? '';
			$this->mergeXorEvent(merged: $merged, event: $event, eventUid: $eventUid, calendarUri: $calendarUri);
		}//end foreach

		return array_values($merged);
	}//end getLinkedEvents()

	/**
	 * The people invited to a linked event, read from the event itself: each
	 * with the name it carries, its address and how far they answered
	 * (`needs-action`, `accepted`, `declined`, `tentative`). Empty when the
	 * event cannot be read.
	 *
	 * @param CalendarLink $link The link row.
	 *
	 * @return array<int, array{name: string, email: string, partstat: string}>
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function attendeesOf(CalendarLink $link): array {
		try {
			$object = $this->calDavBackend->getCalendarObject((int)$link->getCalendarId(), (string)$link->getEventUri());
			if (is_array($object) === false || (string)($object['calendardata'] ?? '') === '') {
				return [];
			}

			$vevent = (Reader::read((string)$object['calendardata'])->select('VEVENT')[0] ?? null);
			if ($vevent === null) {
				return [];
			}

			$attendees = [];
			foreach ($vevent->select('ATTENDEE') as $attendee) {
				$attendees[] = [
					'name' => (string)($attendee['CN'] ?? ''),
					'email' => str_ireplace('mailto:', '', (string)$attendee),
					'partstat' => strtolower((string)($attendee['PARTSTAT'] ?? 'needs-action')),
				];
			}

			return $attendees;
		} catch (Throwable $e) {
			return [];
		}
	}//end attendeesOf()

	/**
	 * Merge a single X-OR-* scan event into the dedupe map.
	 *
	 * Mutates $merged in place:
	 *   - If the (calendarUri, eventUid) key is already present: mark as 'both'
	 *     and refresh free-text fields from the live scan.
	 *   - If only the UID matches (calendar URI missing from the live event):
	 *     mark the existing entry as 'both' via fallback dedupe.
	 *   - Otherwise: insert a new 'xor-only' entry.
	 *
	 * @param array<string,array<string,mixed>> $merged Dedupe map, modified in place by reference.
	 * @param array<string,mixed> $event X-OR-* scan event row.
	 * @param string $eventUid Pre-extracted UID.
	 * @param string $calendarUri Resolved calendar URI (may be '').
	 *
	 * @return void
	 */
	private function mergeXorEvent(array &$merged, array $event, string $eventUid, string $calendarUri): void {
		$key = $calendarUri . '|' . $eventUid;

		if (isset($merged[$key]) === true) {
			// Already present from link-table; mark as both and refresh free-text fields.
			$merged[$key]['source'] = 'both';
			foreach (['summary', 'dtstart', 'dtend', 'location', 'description', 'attendees', 'status'] as $field) {
				if (isset($event[$field]) === true && $event[$field] !== '') {
					$merged[$key][$field] = $event[$field];
				}
			}

			return;
		}

		// Try fallback dedupe by uid alone (calendar URI missing from live event).
		foreach (array_keys($merged) as $existingKey) {
			if (str_ends_with($existingKey, '|' . $eventUid) === true) {
				$merged[$existingKey]['source'] = 'both';
				return;
			}
		}

		$payload = $event;
		$payload['source'] = 'xor-only';
		$payload['calendarUri'] = $calendarUri;
		$merged[$key] = $payload;
	}//end mergeXorEvent()

	/**
	 * List the current user's VEVENT-supporting calendars.
	 *
	 * @return array<int,array<string,mixed>> Each entry has id, uri, displayName, color.
	 *
	 * @throws Exception When no user is logged in.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableCalendars(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$principal = 'principals/users/' . $user->getUID();
		$calendars = $this->calDavBackend->getCalendarsForUser($principal);

		$results = [];
		foreach ($calendars as $calendar) {
			if ($this->calendarSupportsVevent(calendar: $calendar) === false) {
				continue;
			}

			$color = null;
			if (isset($calendar['{http://apple.com/ns/ical/}calendar-color']) === true) {
				$color = (string)$calendar['{http://apple.com/ns/ical/}calendar-color'];
			}

			$results[] = [
				'id' => (int)$calendar['id'],
				'uri' => (string)$calendar['uri'],
				'displayName' => (string)($calendar['{DAV:}displayname'] ?? $calendar['uri']),
				'color' => $color,
			];
		}

		return $results;
	}//end getAvailableCalendars()

	/**
	 * List recent events on a named calendar — picker step 2.
	 *
	 * @param string $calendarUri Calendar URI.
	 * @param int|null $limit Max rows (default 100).
	 * @param DateTimeInterface|null $after Only include events with dtstart >= $after.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws Exception When no user, no calendar, or calendar URI is unknown.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getEventsForCalendar(string $calendarUri, ?int $limit = 100, ?DateTimeInterface $after = null): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$principal = 'principals/users/' . $user->getUID();
		$calendars = $this->calDavBackend->getCalendarsForUser($principal);
		$calendarId = $this->resolveCalendarIdForUri(calendars: $calendars, calendarUri: $calendarUri);

		if ($calendarId === null) {
			throw new Exception('Calendar ' . $calendarUri . ' not found for user');
		}

		$scan = new CalendarPickerScan(calendarId: $calendarId, calendarUri: $calendarUri);
		$scan->cutoff = $after?->getTimestamp();
		$results = [];

		foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $object) {
			$row = $this->parseCalendarObjectForPicker(scan: $scan, object: $object);
			if ($row !== null) {
				$results[] = $row;
			}
		}//end foreach

		// Sort ascending by dtstart, nulls last.
		usort(
			$results,
			static function (array $a, array $b): int {
				$timeA = PHP_INT_MAX;
				if ($a['dtstart'] !== null) {
					$timeA = strtotime((string)$a['dtstart']);
				}

				$timeB = PHP_INT_MAX;
				if ($b['dtstart'] !== null) {
					$timeB = strtotime((string)$b['dtstart']);
				}

				return $timeA <=> $timeB;
			}
		);

		if ($limit !== null && $limit > 0) {
			$results = array_slice($results, 0, $limit);
		}

		return $results;
	}//end getEventsForCalendar()

	/**
	 * Find the numeric calendarId for a named URI in a list of CalDAV calendar rows.
	 *
	 * Only VEVENT-supporting calendars are considered.
	 *
	 * @param array<int,array<string,mixed>> $calendars Rows from CalDavBackend::getCalendarsForUser().
	 * @param string $calendarUri URI to look up.
	 *
	 * @return int|null Numeric calendar id, or null when not found.
	 */
	private function resolveCalendarIdForUri(array $calendars, string $calendarUri): ?int {
		foreach ($calendars as $calendar) {
			if (($calendar['uri'] ?? null) === $calendarUri
				&& $this->calendarSupportsVevent(calendar: $calendar) === true
			) {
				return (int)$calendar['id'];
			}
		}

		return null;
	}//end resolveCalendarIdForUri()

	/**
	 * Parse a single CalDAV object into a picker result row.
	 *
	 * Returns null when the object is not a parseable VEVENT, has no UID,
	 * or falls before the cutoff timestamp.
	 *
	 * @param CalendarPickerScan  $scan   The calendar being listed and the cutoff.
	 * @param array<string,mixed> $object Lightweight object row from getCalendarObjects().
	 *
	 * @return array<string,mixed>|null Picker row, or null to skip this object.
	 */
	private function parseCalendarObjectForPicker(CalendarPickerScan $scan, array $object): ?array {
		$calendarData = $this->objectCalendarData(calendarId: $scan->calendarId, objectUri: $object['uri']);
		if ($calendarData === null || strpos($calendarData, 'VEVENT') === false) {
			return null;
		}

		try {
			return $scan->pickerRow(vevent: Reader::read($calendarData)->VEVENT, objectUri: (string)$object['uri']);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to parse calendar event for picker: ' . $e->getMessage(),
				['uri' => $object['uri']]
			);
		}//end try

		return null;
	}//end parseCalendarObjectForPicker()

	/**
	 * Locate a VEVENT on a named calendar and pull cacheable fields.
	 *
	 * @param string $userId Current user id.
	 * @param string $calendarUri Calendar URI.
	 * @param string $eventUid VEVENT UID.
	 *
	 * @return array{calendarId:int, eventUri:string, summary:?string, dtstart:?DateTime, dtend:?DateTime, location:?string}|null
	 */
	private function locateEventOnCalendar(string $userId, string $calendarUri, string $eventUid): ?array {
		$calendarId = $this->calendarIdByUri(userId: $userId, calendarUri: $calendarUri);
		if ($calendarId === null) {
			return null;
		}

		foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $object) {
			$calendarData = $this->objectCalendarData(calendarId: $calendarId, objectUri: $object['uri']);
			if ($calendarData === null || strpos($calendarData, $eventUid) === false) {
				continue;
			}

			$fields = $this->parseVeventObjectForLocate(calendarData: $calendarData, expectedUid: $eventUid);
			if ($fields !== null) {
				return ['calendarId' => $calendarId, 'eventUri' => (string)$object['uri']] + $fields;
			}
		}

		return null;
	}//end locateEventOnCalendar()
	/**
	 * The id of the user's calendar with the given URI, of any component type.
	 *
	 * @param string $userId      User id.
	 * @param string $calendarUri Calendar URI.
	 *
	 * @return int|null The calendar id; null when the user has no such calendar.
	 */
	private function calendarIdByUri(string $userId, string $calendarUri): ?int {
		foreach ($this->calDavBackend->getCalendarsForUser('principals/users/' . $userId) as $calendar) {
			if (($calendar['uri'] ?? null) === $calendarUri) {
				return (int)$calendar['id'];
			}
		}

		return null;
	}//end calendarIdByUri()
	/**
	 * Parse iCal data and return the cacheable event fields when the UID matches.
	 *
	 * Returns null when the data cannot be parsed, contains no VEVENT,
	 * or the UID does not match.
	 *
	 * @param string $calendarData Raw iCal data.
	 * @param string $expectedUid  UID to match against.
	 *
	 * @return array{summary:?string, dtstart:?DateTime, dtend:?DateTime, location:?string}|null
	 */
	private function parseVeventObjectForLocate(string $calendarData, string $expectedUid): ?array {
		try {
			$vevent = Reader::read($calendarData)->VEVENT;
			if ($vevent === null || isset($vevent->UID) === false || (string)$vevent->UID !== $expectedUid) {
				return null;
			}

			return [
				'summary' => $this->veventText(vevent: $vevent, name: 'SUMMARY'),
				'dtstart' => $this->veventDateTime(vevent: $vevent, name: 'DTSTART'),
				'dtend' => $this->veventDateTime(vevent: $vevent, name: 'DTEND'),
				'location' => $this->veventText(vevent: $vevent, name: 'LOCATION'),
			];
		} catch (Throwable $e) {
			$this->logger->warning('Failed to parse VEVENT while locating event ' . $expectedUid . ': ' . $e->getMessage());
		}

		return null;
	}//end parseVeventObjectForLocate()
	/**
	 * The iCal data of a CalDAV object; null when the object is missing or empty.
	 *
	 * @param int   $calendarId Numeric calendar id.
	 * @param mixed $objectUri  Object URI as the CalDAV store lists it.
	 *
	 * @return string|null The iCal data.
	 */
	private function objectCalendarData(int $calendarId, mixed $objectUri): ?string {
		$fullObject = $this->calDavBackend->getCalendarObject($calendarId, $objectUri);
		if ($fullObject === null || empty($fullObject['calendardata']) === true) {
			return null;
		}

		return $fullObject['calendardata'];
	}//end objectCalendarData()
	/**
	 * A date-time property of a VEVENT; null when the event does not have it.
	 *
	 * @param Component $vevent The VEVENT.
	 * @param string    $name   Property name, such as DTSTART.
	 *
	 * @return DateTime|null The value.
	 */
	private function veventDateTime(Component $vevent, string $name): ?DateTime {
		if (isset($vevent->{$name}) === false) {
			return null;
		}

		return DateTime::createFromInterface($vevent->{$name}->getDateTime());
	}//end veventDateTime()
	/**
	 * A text property of a VEVENT; null when the event does not have it.
	 *
	 * @param Component $vevent The VEVENT.
	 * @param string    $name   Property name, such as LOCATION.
	 *
	 * @return string|null The value.
	 */
	private function veventText(Component $vevent, string $name): ?string {
		if (isset($vevent->{$name}) === false) {
			return null;
		}

		return (string)$vevent->{$name};
	}//end veventText()

	/**
	 * Build a {calendarId → calendarUri} map for the current user.
	 *
	 * @param string $userId Current user id.
	 *
	 * @return array<int,string>
	 */
	private function buildCalendarMapForUser(string $userId): array {
		try {
			$principal = 'principals/users/' . $userId;
			$calendars = $this->calDavBackend->getCalendarsForUser($principal);
		} catch (Throwable $e) {
			return [];
		}

		$map = [];
		foreach ($calendars as $calendar) {
			if (isset($calendar['id'], $calendar['uri']) === true) {
				$map[(int)$calendar['id']] = (string)$calendar['uri'];
			}
		}

		return $map;
	}//end buildCalendarMapForUser()

	/**
	 * Resolve a calendar URI given its numeric id.
	 *
	 * @param string $userId Current user id.
	 * @param int $calendarId Calendar numeric id.
	 *
	 * @return string|null
	 */
	private function resolveCalendarUriForId(string $userId, int $calendarId): ?string {
		$map = $this->buildCalendarMapForUser(userId: $userId);
		return $map[$calendarId] ?? null;
	}//end resolveCalendarUriForId()

	/**
	 * Decide whether a CalDAV calendar row supports VEVENT.
	 *
	 * @param array<string,mixed> $calendar Row from CalDavBackend::getCalendarsForUser().
	 *
	 * @return bool
	 */
	private function calendarSupportsVevent(array $calendar): bool {
		$components = $calendar['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
		if ($components === null) {
			return false;
		}

		if (is_object($components) === true && method_exists($components, 'getValue') === true) {
			return $this->componentListContainsVevent(components: $components->getValue());
		}

		if (is_string($components) === true) {
			return stripos($components, 'VEVENT') !== false;
		}

		if (is_iterable($components) === true) {
			return $this->componentListContainsVevent(components: $components);
		}

		return false;
	}//end calendarSupportsVevent()

	/**
	 * Check whether an iterable list of component names contains 'VEVENT'.
	 *
	 * @param iterable<mixed> $components List of component name strings.
	 *
	 * @return bool
	 */
	private function componentListContainsVevent(iterable $components): bool {
		foreach ($components as $comp) {
			if (strtoupper((string)$comp) === 'VEVENT') {
				return true;
			}
		}

		return false;
	}//end componentListContainsVevent()
}//end class
