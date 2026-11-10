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
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
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
	 * @param CalendarLinkMapperInterface $linkMapper           Link-table mapper.
	 * @param CalendarEventService        $calendarEventService Legacy CalDAV-property service.
	 * @param CalDavBackend               $calDavBackend        CalDAV backend for direct picker queries.
	 * @param LinkSessionRuntime          $runtime              User session and logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalendarLinkMapperInterface $linkMapper,
		private readonly CalendarEventService $calendarEventService,
		private readonly CalDavBackend $calDavBackend,
		private readonly LinkSessionRuntime $runtime,
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
	public function linkEvent(string $objectUuid, int $registerId, int $schemaId, string $calendarUri, string $eventUid): CalendarLink {
		$target = (new LinkTarget(objectUuid: $objectUuid, registerId: $registerId))->inSchema(schemaId: $schemaId);
		return $this->linkLocatedEvent(target: $target, key: new CalendarEventKey(calendarUri: $calendarUri, eventUid: $eventUid));
	}//end linkEvent()

	/**
	 * Insert a link-table row for an existing VEVENT, with its cacheable fields.
	 *
	 * @param LinkTarget       $target The object, register and schema to link to.
	 * @param CalendarEventKey $key    The calendar URI and event UID.
	 *
	 * @return CalendarLink The persisted link row.
	 *
	 * @throws Exception When no user, or the event is not on that calendar.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkLocatedEvent(LinkTarget $target, CalendarEventKey $key): CalendarLink {
		$user = $this->requireUser();

		// Locate the VEVENT on the named calendar so we can cache summary/dtstart/etc.
		$located = $this->locateEventOnCalendar(userId: $user->getUID(), key: $key);

		if ($located === null) {
			throw new Exception('Calendar event ' . $key->eventUid . ' not found on calendar ' . $key->calendarUri);
		}

		$link = $this->newLink(target: $target);
		$link->setCalendarUri($key->calendarUri);
		$link->setCalendarId((int)$located['calendarId']);
		$link->setEventUid($key->eventUid);
		$link->setEventUri((string)$located['eventUri']);
		$link->setSummary($located['summary']);
		$link->setDtstart($located['dtstart']);
		$link->setDtend($located['dtend']);
		$link->setLocation($located['location']);
		$this->stampLinkedBy(link: $link, uid: $user->getUID());
		$link->setTaggedWithXor(false);

		return $this->linkMapper->insert(entity: $link);
	}//end linkLocatedEvent()

	/**
	 * The logged-in user.
	 *
	 * @return IUser
	 *
	 * @throws Exception When no user is logged in.
	 */
	private function requireUser(): IUser {
		$user = $this->runtime->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return $user;
	}//end requireUser()

	/**
	 * A new, unsaved link row on the target's object, register and schema.
	 *
	 * @param LinkTarget $target The object, register and schema.
	 *
	 * @return CalendarLink
	 */
	private function newLink(LinkTarget $target): CalendarLink {
		$link = new CalendarLink();
		$link->setObjectUuid($target->objectUuid);
		$link->setRegisterId($target->registerId);
		$link->setSchemaId($target->schemaId());
		return $link;
	}//end newLink()

	/**
	 * Record who linked the row, and when.
	 *
	 * @param CalendarLink $link The link row.
	 * @param string       $uid  The linking user's id.
	 *
	 * @return void
	 */
	private function stampLinkedBy(CalendarLink $link, string $uid): void {
		$link->setLinkedBy($uid);
		$link->setLinkedAt(new DateTime());
	}//end stampLinkedBy()

	/**
	 * Create a VEVENT through CalendarEventService and insert its link-table mirror row.
	 *
	 * @param LinkTarget          $target    The object, register and schema to link to.
	 * @param array<string,mixed> $eventData The event payload, with an optional `objectTitle`.
	 *
	 * @return CalendarLink The persisted link row.
	 *
	 * @throws Exception When no user, or the event could not be created.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createLinkedEvent(LinkTarget $target, array $eventData): CalendarLink {
		$user = $this->requireUser();

		$event = $this->calendarEventService->createEvent(
			registerId: $target->registerId,
			schemaId: $target->schemaId(),
			objectUuid: $target->objectUuid,
			objectTitle: (string)($eventData['objectTitle'] ?? $target->objectUuid),
			data: $eventData
		);

		if ($event === null) {
			throw new Exception('Failed to create calendar event');
		}

		// CalendarEventService::createEvent returns the veventToArray
		// shape with id=eventUri and calendarId stringified.
		$calendarId = (int)($event['calendarId'] ?? 0);
		$calendarUri = $this->resolveCalendarUriForId(userId: $user->getUID(), calendarId: $calendarId);

		$link = $this->newLink(target: $target);
		$link->setCalendarUri($calendarUri ?? '');
		$link->setCalendarId($calendarId);
		$this->applyCreatedEvent(link: $link, event: $event);
		$this->stampLinkedBy(link: $link, uid: $user->getUID());
		$link->setTaggedWithXor(true);

		return $this->linkMapper->insert(entity: $link);
	}//end createLinkedEvent()

	/**
	 * Copy the uid, uri, summary, dates and location of a created event onto its link row.
	 *
	 * @param CalendarLink        $link  The link row.
	 * @param array<string,mixed> $event The created event, in veventToArray shape.
	 *
	 * @return void
	 */
	private function applyCreatedEvent(CalendarLink $link, array $event): void {
		$link->setEventUid((string)($event['uid'] ?? ''));
		$link->setEventUri((string)($event['id'] ?? ''));
		$link->setSummary((string)($event['summary'] ?? ''));
		$link->setDtstart($this->parseEventDateTime(value: $event['dtstart'] ?? null));
		$link->setDtend($this->parseEventDateTime(value: $event['dtend'] ?? null));
		$link->setLocation($this->locationOf(event: $event));
	}//end applyCreatedEvent()

	/**
	 * The location of a created event as a string, or null when it has none.
	 *
	 * @param array<string,mixed> $event The created event.
	 *
	 * @return string|null
	 */
	private function locationOf(array $event): ?string {
		if (isset($event['location']) === true) {
			return (string)$event['location'];
		}

		return null;
	}//end locationOf()

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
		$existing = $this->findExistingLink(objectUuid: $objectUuid, eventUid: $eventUid);
		$xorTarget = null;
		if ($existing !== null) {
			$xorTarget = $this->xorTargetOf(link: $existing);
		}

		$this->linkMapper->deleteByObjectAndEvent(objectUuid: $objectUuid, eventUid: $eventUid);

		// If the row had matching X-OR-* tags, strip them.
		if ($xorTarget !== null) {
			$this->stripXorProperties(xorTarget: $xorTarget, eventUid: $eventUid);
			return;
		}

		// Fall back to the legacy X-OR-* scan: maybe this event
		// pre-dates the link table and only carries the custom
		// properties. Walk the user's events to find a match.
		$this->unlinkLegacyXorEvent(objectUuid: $objectUuid, eventUid: $eventUid);
	}//end unlinkEvent()

	/**
	 * The link-table row for an object and event, or null when there is none.
	 *
	 * @param string $objectUuid OR object UUID.
	 * @param string $eventUid   VEVENT UID.
	 *
	 * @return CalendarLink|null
	 */
	private function findExistingLink(string $objectUuid, string $eventUid): ?CalendarLink {
		try {
			return $this->linkMapper->findByObjectAndEvent(
				objectUuid: $objectUuid,
				eventUid: $eventUid
			);
		} catch (DoesNotExistException $e) {
			// No link-table row — this is fine. We may still need to
			// unlink an X-OR-only event below.
			return null;
		}
	}//end findExistingLink()

	/**
	 * The calendar id and event uri whose X-OR-* tags must be stripped, when the row carries them.
	 *
	 * @param CalendarLink $link The link-table row.
	 *
	 * @return array{calendarId:int,eventUri:string}|null
	 */
	private function xorTargetOf(CalendarLink $link): ?array {
		$taggedWithXor = (bool)$link->getTaggedWithXor();
		$calendarId = $link->getCalendarId();
		$eventUri = $link->getEventUri();
		if ($taggedWithXor === true && $calendarId !== null && $eventUri !== null) {
			return ['calendarId' => $calendarId, 'eventUri' => $eventUri];
		}

		return null;
	}//end xorTargetOf()

	/**
	 * Strip the X-OPENREGISTER properties from a tagged event; a failure is logged.
	 *
	 * @param array{calendarId:int,eventUri:string} $xorTarget The tagged event.
	 * @param string                                $eventUid  VEVENT UID, for the log line.
	 *
	 * @return void
	 */
	private function stripXorProperties(array $xorTarget, string $eventUid): void {
		try {
			$this->calendarEventService->unlinkEvent(
				calendarId: (string)$xorTarget['calendarId'],
				eventUri: $xorTarget['eventUri']
			);
		} catch (Throwable $e) {
			$this->runtime->logger->warning(
				'Failed to strip X-OPENREGISTER properties for event ' . $eventUid . ': ' . $e->getMessage()
			);
		}
	}//end stripXorProperties()

	/**
	 * Unlink the first legacy X-OR-* tagged event with the UID; a failure is logged.
	 *
	 * @param string $objectUuid OR object UUID.
	 * @param string $eventUid   VEVENT UID.
	 *
	 * @return void
	 */
	private function unlinkLegacyXorEvent(string $objectUuid, string $eventUid): void {
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
			$this->runtime->logger->info(
				'Legacy X-OR unlink fallback failed for event ' . $eventUid . ': ' . $e->getMessage()
			);
		}
	}//end unlinkLegacyXorEvent()

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
		$merged = new LinkedEventMerge();

		// 1. Link-table rows
		foreach ($this->linkMapper->findByObjectUuid(objectUuid: $objectUuid) as $link) {
			$key = $link->getCalendarUri() . '|' . $link->getEventUid();
			$payload = $link->jsonSerialize();
			$payload['source'] = 'link-table';
			$payload['attendees'] = $this->attendeesOf(link: $link);
			$merged->addLinkRow(key: $key, payload: $payload);
		}

		// 2. Legacy X-OR-* scan (best-effort; degrade to empty list)
		$xorEvents = $this->scanXorEvents(objectUuid: $objectUuid);

		$userId = $this->runtime->userSession->getUser()?->getUID();
		$calendarMap = [];
		if ($userId !== null) {
			$calendarMap = $this->buildCalendarMapForUser(userId: $userId);
		}

		foreach ($xorEvents as $event) {
			$merged->addXorEvent(event: $event, calendarUri: $calendarMap[(int)($event['calendarId'] ?? 0)] ?? '');
		}

		return $merged->rows();
	}//end getLinkedEvents()

	/**
	 * The legacy X-OR-* tagged events of an object; none when the scan fails.
	 *
	 * @param string $objectUuid OR object UUID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function scanXorEvents(string $objectUuid): array {
		try {
			return $this->calendarEventService->getEventsForObject(objectUuid: $objectUuid);
		} catch (Throwable $e) {
			$this->runtime->logger->info(
				'X-OR-* scan failed during getLinkedEvents: ' . $e->getMessage()
			);
			return [];
		}
	}//end scanXorEvents()

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

			return $this->attendeeRows(vevent: $vevent);
		} catch (Throwable $e) {
			return [];
		}
	}//end attendeesOf()

	/**
	 * The ATTENDEE properties of a VEVENT as name/email/partstat rows.
	 *
	 * @param Component $vevent The VEVENT.
	 *
	 * @return list<array{name:string,email:string,partstat:string}>
	 */
	private function attendeeRows(Component $vevent): array {
		$attendees = [];
		foreach ($vevent->select('ATTENDEE') as $attendee) {
			$attendees[] = [
				'name' => (string)($attendee['CN'] ?? ''),
				'email' => str_ireplace('mailto:', '', (string)$attendee),
				'partstat' => strtolower((string)($attendee['PARTSTAT'] ?? 'needs-action')),
			];
		}

		return $attendees;
	}//end attendeeRows()

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
		$user = $this->runtime->userSession->getUser();
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
	 * @param string               $calendarUri Calendar URI.
	 * @param CalendarPickerWindow $window      The earliest start and the row cap.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws Exception When no user, no calendar, or calendar URI is unknown.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getEventsForCalendar(string $calendarUri, CalendarPickerWindow $window): array {
		$results = $this->pickerRows(scan: $this->pickerScan(calendarUri: $calendarUri, after: $window->after));

		return $this->limitRows(rows: $this->sortedByStart(rows: $results), limit: $window->limit ?? 0);
	}//end getEventsForCalendar()

	/**
	 * The picker scan of the user's calendar with the given URI.
	 *
	 * @param string                 $calendarUri The calendar URI.
	 * @param DateTimeInterface|null $after       Only include events with dtstart >= $after.
	 *
	 * @return CalendarPickerScan
	 *
	 * @throws Exception When no user, or the calendar URI is unknown.
	 */
	private function pickerScan(string $calendarUri, ?DateTimeInterface $after): CalendarPickerScan {
		$user = $this->requireUser();

		$principal = 'principals/users/' . $user->getUID();
		$calendars = $this->calDavBackend->getCalendarsForUser($principal);
		$calendarId = $this->resolveCalendarIdForUri(calendars: $calendars, calendarUri: $calendarUri);

		if ($calendarId === null) {
			throw new Exception('Calendar ' . $calendarUri . ' not found for user', 404);
		}

		$scan = new CalendarPickerScan(calendarId: $calendarId, calendarUri: $calendarUri);
		$scan->cutoff = $after?->getTimestamp();

		return $scan;
	}//end pickerScan()

	/**
	 * Picker rows sorted ascending by dtstart, nulls last.
	 *
	 * @param array<int,array<string,mixed>> $rows The picker rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sortedByStart(array $rows): array {
		usort(
			$rows,
			static fn (array $a, array $b): int => self::startTimeOf(row: $a) <=> self::startTimeOf(row: $b)
		);

		return $rows;
	}//end sortedByStart()

	/**
	 * The picker rows of every VEVENT object on the scanned calendar.
	 *
	 * @param CalendarPickerScan $scan The calendar and cutoff being scanned.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function pickerRows(CalendarPickerScan $scan): array {
		$results = [];
		foreach ($this->calDavBackend->getCalendarObjects($scan->calendarId) as $object) {
			$row = $this->parseCalendarObjectForPicker(scan: $scan, object: $object);
			if ($row !== null) {
				$results[] = $row;
			}
		}

		return $results;
	}//end pickerRows()

	/**
	 * The sort key of a picker row: its start time, or PHP_INT_MAX when it has none.
	 *
	 * @param array<string,mixed> $row A picker row.
	 *
	 * @return int|false
	 */
	private static function startTimeOf(array $row): int|false {
		if ($row['dtstart'] === null) {
			return PHP_INT_MAX;
		}

		return strtotime((string)$row['dtstart']);
	}//end startTimeOf()

	/**
	 * The first rows up to a positive limit; all rows otherwise.
	 *
	 * @param array<int,array<string,mixed>> $rows  The sorted rows.
	 * @param int                            $limit The limit; 0 or less for none.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function limitRows(array $rows, int $limit): array {
		if ($limit > 0) {
			return array_slice($rows, 0, $limit);
		}

		return $rows;
	}//end limitRows()

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
			$this->runtime->logger->warning(
				'Failed to parse calendar event for picker: ' . $e->getMessage(),
				['uri' => $object['uri']]
			);
		}//end try

		return null;
	}//end parseCalendarObjectForPicker()

	/**
	 * Locate a VEVENT on a named calendar and pull cacheable fields.
	 *
	 * @param string           $userId Current user id.
	 * @param CalendarEventKey $key    Calendar URI and VEVENT UID.
	 *
	 * @return array{calendarId:int, eventUri:string, summary:?string, dtstart:?DateTime, dtend:?DateTime, location:?string}|null
	 */
	private function locateEventOnCalendar(string $userId, CalendarEventKey $key): ?array {
		$calendarId = $this->calendarIdByUri(userId: $userId, calendarUri: $key->calendarUri);
		if ($calendarId === null) {
			return null;
		}

		foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $object) {
			$located = $this->locateInObject(
				ref: new CalendarObjectRef(calendarId: $calendarId, uri: (string)$object['uri']),
				eventUid: $key->eventUid
			);
			if ($located !== null) {
				return $located;
			}
		}

		return null;
	}//end locateEventOnCalendar()

	/**
	 * The located fields of one calendar object when it holds the VEVENT with the UID.
	 *
	 * @param CalendarObjectRef $ref      The calendar id and object uri.
	 * @param string            $eventUid VEVENT UID to find.
	 *
	 * @return array<string,mixed>|null
	 */
	private function locateInObject(CalendarObjectRef $ref, string $eventUid): ?array {
		$calendarData = $this->objectCalendarData(calendarId: $ref->calendarId, objectUri: $ref->uri);
		if ($calendarData === null || strpos($calendarData, $eventUid) === false) {
			return null;
		}

		$fields = $this->parseVeventObjectForLocate(calendarData: $calendarData, expectedUid: $eventUid);
		if ($fields === null) {
			return null;
		}

		return ['calendarId' => $ref->calendarId, 'eventUri' => $ref->uri] + $fields;
	}//end locateInObject()
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
			if ($this->hasUid(vevent: $vevent, expectedUid: $expectedUid) === false) {
				return null;
			}

			return [
				'summary' => $this->veventText(vevent: $vevent, name: 'SUMMARY'),
				'dtstart' => $this->veventDateTime(vevent: $vevent, name: 'DTSTART'),
				'dtend' => $this->veventDateTime(vevent: $vevent, name: 'DTEND'),
				'location' => $this->veventText(vevent: $vevent, name: 'LOCATION'),
			];
		} catch (Throwable $e) {
			$this->runtime->logger->warning('Failed to parse VEVENT while locating event ' . $expectedUid . ': ' . $e->getMessage());
		}

		return null;
	}//end parseVeventObjectForLocate()

	/**
	 * Whether the parsed VEVENT exists and carries the expected UID.
	 *
	 * @param mixed  $vevent      The parsed VEVENT, or null.
	 * @param string $expectedUid UID to match.
	 *
	 * @return bool
	 */
	private function hasUid(mixed $vevent, string $expectedUid): bool {
		return $vevent !== null && isset($vevent->UID) === true && (string)$vevent->UID === $expectedUid;
	}//end hasUid()
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

		return match (true) {
			is_object($components) === true && method_exists($components, 'getValue') === true
				=> $this->componentListContainsVevent(components: $components->getValue()),
			is_string($components) === true => stripos($components, 'VEVENT') !== false,
			is_iterable($components) === true => $this->componentListContainsVevent(components: $components),
			default => false,
		};
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
