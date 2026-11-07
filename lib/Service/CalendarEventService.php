<?php

/**
 * CalendarEventService
 *
 * Service that wraps CalDAV VEVENT operations for linking calendar events to OpenRegister objects.
 * Events are stored as standard VEVENT items in the user's Nextcloud calendar with
 * X-OPENREGISTER-* properties for linking and an RFC 9253 LINK property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-webhookeventlistener-must-extract-structured-payloads-from-all-event-types
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Service\CalendarEvent\UserCalendarSelector;
use OCA\OpenRegister\Service\CalendarEvent\VeventCodec;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Document;
use Sabre\VObject\Reader;

/**
 * CalendarEventService wraps CalDAV VEVENT operations for OpenRegister objects.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
class CalendarEventService implements ObjectCalendarEventsInterface {

	/**
	 * CalDAV backend.
	 *
	 * @var CalDavBackend
	 */
	private readonly CalDavBackend $calDavBackend;

	/**
	 * User session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * URL generator (webroot-aware deep links).
	 *
	 * @var IURLGenerator
	 */
	private readonly IURLGenerator $urlGenerator;

	/**
	 * Chooses and pins the calendar events are written to.
	 *
	 * @var UserCalendarSelector
	 */
	private readonly UserCalendarSelector $calendarSelector;

	/**
	 * Reads parsed VEVENTs into event arrays and serialises new ones.
	 *
	 * @var VeventCodec
	 */
	private readonly VeventCodec $veventCodec;

	/**
	 * Constructor.
	 *
	 * @param CalDavBackend $calDavBackend CalDAV backend
	 * @param IUserSession $userSession User session
	 * @param IConfig $config NC config (user-value store)
	 * @param LoggerInterface $logger Logger
	 * @param IURLGenerator $urlGenerator URL generator for deep links
	 *
	 * @return void
	 */
	public function __construct(
		CalDavBackend $calDavBackend,
		IUserSession $userSession,
		IConfig $config,
		LoggerInterface $logger,
		IURLGenerator $urlGenerator,
	) {
		$this->calDavBackend = $calDavBackend;
		$this->userSession = $userSession;
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
		$this->calendarSelector = new UserCalendarSelector(config: $config, logger: $logger);
		$this->veventCodec = new VeventCodec();
	}//end __construct()

	/**
	 * Get all calendar events linked to a specific OpenRegister object.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return array Array of event arrays in JSON-friendly format
	 *
	 * @throws Exception If no user is logged in or no calendar found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getEventsForObject(string $objectUuid): array {
		$calendar = $this->findUserCalendar();

		$events = [];
		foreach ($this->parsedEventsIn(calendarId: $calendar['id'], needle: $objectUuid) as $event) {
			if ($event['objectUuid'] !== $objectUuid) {
				continue;
			}

			$linked = $this->withDeepLink(event: $event, calendarUri: ($calendar['uri'] ?? null));
			if ($linked !== null) {
				$events[] = $linked;
			}
		}

		return $events;
	}//end getEventsForObject()

	/**
	 * Parse every VEVENT in a calendar whose raw data mentions a needle.
	 *
	 * Objects that are missing, empty, do not mention the needle or are not
	 * VEVENTs are skipped; one that fails to parse is logged and skipped.
	 *
	 * @param mixed  $calendarId The calendar ID.
	 * @param string $needle     Text the raw iCalendar data must contain.
	 *
	 * @return list<array<string, mixed>> The parsed events.
	 */
	private function parsedEventsIn(mixed $calendarId, string $needle): array {
		$events = [];
		foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $calendarObject) {
			$event = $this->parseStoredEvent(calendarId: $calendarId, uri: $calendarObject['uri'], needle: $needle);
			if ($event !== null) {
				$events[] = $event;
			}
		}

		return $events;
	}//end parsedEventsIn()

	/**
	 * Parse one stored calendar object into an event array, if it is a VEVENT mentioning the needle.
	 *
	 * @param mixed  $calendarId The calendar ID.
	 * @param string $uri        The calendar object URI.
	 * @param string $needle     Text the raw iCalendar data must contain.
	 *
	 * @return array<string, mixed>|null The event, or null when skipped.
	 */
	private function parseStoredEvent(mixed $calendarId, string $uri, string $needle): ?array {
		$calendarData = ($this->calDavBackend->getCalendarObject($calendarId, $uri)['calendardata'] ?? null);
		if (empty($calendarData) === true || $this->mentionsAll(data: $calendarData, needles: [$needle, 'VEVENT']) === false) {
			return null;
		}

		try {
			return $this->veventToArray(calendarData: $calendarData, calendarId: (string)$calendarId, uri: $uri);
		} catch (Exception $e) {
			$this->logParseFailure(e: $e, uri: $uri);
			return null;
		}
	}//end parseStoredEvent()

	/**
	 * Whether a string contains every needle.
	 *
	 * @param string       $data    The haystack.
	 * @param list<string> $needles The needles.
	 *
	 * @return bool
	 */
	private function mentionsAll(string $data, array $needles): bool {
		foreach ($needles as $needle) {
			if (strpos($data, $needle) === false) {
				return false;
			}
		}

		return true;
	}//end mentionsAll()

	/**
	 * Add the Calendar-app deep link to an event, when one resolves.
	 *
	 * A failure here drops the event with the same warning a parse failure
	 * gives, as it always has.
	 *
	 * @param array       $event       The parsed event.
	 * @param string|null $calendarUri The CalDAV calendar URI owning the event.
	 *
	 * @return array|null The event, or null when building the link failed.
	 */
	private function withDeepLink(array $event, ?string $calendarUri): ?array {
		try {
			$deepLink = $this->buildEventDeepLink(calendarUri: $calendarUri, objectUri: $event['id']);
		} catch (Exception $e) {
			$this->logParseFailure(e: $e, uri: $event['id']);
			return null;
		}

		if ($deepLink !== null) {
			$event['url'] = $deepLink;
		}

		return $event;
	}//end withDeepLink()

	/**
	 * Log a calendar object that could not be turned into an event.
	 *
	 * @param Exception $e   The failure.
	 * @param string    $uri The calendar object URI.
	 *
	 * @return void
	 */
	private function logParseFailure(Exception $e, string $uri): void {
		$this->logger->warning('Failed to parse calendar event: ' . $e->getMessage(), ['uri' => $uri]);
	}//end logParseFailure()

	/**
	 * Build a webroot-aware deep-link to a specific calendar event.
	 *
	 * The Calendar app opens a specific event via its `/edit/{objectId}` route
	 * (`calendar.view.index` postfix `direct.edit`), where `objectId` is the
	 * base64 of the object's full DAV path *including* the `/remote.php/dav/`
	 * prefix — i.e. `{webroot}/remote.php/dav/calendars/{userId}/{calendarUri}/{objectUri}`
	 * (this matches the `objectId` the Calendar UI itself generates; the app
	 * does `atob(objectId)` and fetches that DAV href). NC cannot generate the
	 * postfixed route by name (it yields an empty string), so we resolve the
	 * webroot-aware app base via `calendar.view.index` and append the
	 * `edit/{token}` segment ourselves (token URL-encoded so the base64 `/`/`+`
	 * survive as one path segment).
	 *
	 * Returns null (record gets no `url`) when a required part is missing or the
	 * Calendar app / route is unavailable.
	 *
	 * @param string|null $calendarUri The CalDAV calendar URI owning the event.
	 * @param string|null $objectUri The VEVENT object URI (.ics).
	 *
	 * @return string|null The deep-link URL, or null when not resolvable.
	 */
	private function buildEventDeepLink(?string $calendarUri, ?string $objectUri): ?string {
		$user = $this->userSession->getUser();
		if ($user === null || in_array($calendarUri, [null, ''], true) === true || in_array($objectUri, [null, ''], true) === true) {
			return null;
		}

		$base = $this->calendarAppBase();
		if ($base === null) {
			return null;
		}

		return rtrim($base, '/') . '/edit/' . $this->eventDavToken(userId: $user->getUID(), path: $calendarUri . '/' . $objectUri);
	}//end buildEventDeepLink()

	/**
	 * The Calendar app's objectId for an event: its URL-encoded, base64 DAV href.
	 *
	 * The Calendar app decodes objectId to a DAV href and fetches it, so it
	 * must carry the (webroot-aware) `/remote.php/dav/` prefix.
	 *
	 * @param string $userId The calendar owner's user id.
	 * @param string $path   The `{calendarUri}/{objectUri}` path.
	 *
	 * @return string The token.
	 */
	private function eventDavToken(string $userId, string $path): string {
		$davPath = $this->urlGenerator->getWebroot() . '/remote.php/dav/calendars/' . $userId . '/' . $path;

		return rawurlencode(base64_encode($davPath));
	}//end eventDavToken()

	/**
	 * The webroot-aware base URL of the Calendar app, or null when it is unavailable.
	 *
	 * @return string|null The base URL.
	 */
	private function calendarAppBase(): ?string {
		try {
			$base = $this->urlGenerator->linkToRoute('calendar.view.index');
		} catch (\Throwable $e) {
			return null;
		}

		if ($base === '') {
			return null;
		}

		return $base;
	}//end calendarAppBase()

	/**
	 * Get all VEVENTs across the acting user's VEVENT-supporting calendars.
	 *
	 * Read-only directory listing that mirrors {@see \OCA\OpenRegister\Service\TaskService::getAllUserTasks()}
	 * for events: it enumerates every calendar the user owns (not just the pinned
	 * one), skips non-VEVENT objects, and returns the parsed event arrays sorted by
	 * start date (soonest first, undated last). Backs the `calendar-event-source`
	 * ObjectSourceProvider.
	 *
	 * @param int $limit Maximum number of events to return.
	 * @param int $offset Number of events to skip.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} Events with total count.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getAllUserEvents(int $limit = 200, int $offset = 0): array {
		$principal = 'principals/users/' . $this->currentUserId();

		$allEvents = [];
		foreach ($this->calDavBackend->getCalendarsForUser($principal) as $calendar) {
			if ($this->calendarSelector->calendarSupportsVevent(calendar: $calendar) === true) {
				$allEvents = array_merge($allEvents, $this->parsedEventsIn(calendarId: $calendar['id'], needle: 'VEVENT'));
			}
		}

		$allEvents = $this->sortedByStart(events: $allEvents);

		return [
			'results' => array_slice($allEvents, $offset, $limit),
			'total' => count($allEvents),
		];
	}//end getAllUserEvents()

	/**
	 * Events sorted by start date, soonest first and undated last.
	 *
	 * @param list<array<string, mixed>> $events The events.
	 *
	 * @return list<array<string, mixed>> The sorted events.
	 */
	private function sortedByStart(array $events): array {
		usort(
			array: $events,
			callback: fn ($a, $b) => strcmp(($a['dtstart'] ?? '9999-12-31'), ($b['dtstart'] ?? '9999-12-31'))
		);

		return $events;
	}//end sortedByStart()

	/**
	 * Create a new CalDAV event linked to an OpenRegister object.
	 *
	 * @param int $registerId The register ID
	 * @param int $schemaId The schema ID
	 * @param string $objectUuid The object UUID
	 * @param string $objectTitle The object title for the LINK label
	 * @param array $data Event data: summary, dtstart, dtend, location, description, attendees
	 *
	 * @return array|null The created event in JSON-friendly format
	 *
	 * @throws Exception If no user or calendar found, or if the data carries no
	 *                   usable dtstart
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function createEvent(
		int $registerId,
		int $schemaId,
		string $objectUuid,
		string $objectTitle,
		array $data,
	): ?array {
		// A VEVENT without DTSTART is not merely incomplete, it is unreachable.
		// Its firstoccurence is NULL so it matches no time-range query and no
		// calendar view can show it, and Sabre's ITip plugin then rejects both
		// DELETE and PUT on it with "An event MUST have a DTSTART property".
		// Nothing removes such a row but a SQL delete.
		//
		// The guard lives here rather than in the controllers because this is
		// the one place a VEVENT is serialised, and both callers reach it:
		// CalendarEventsController and TaskEventsController each validated only
		// the summary. Both turn a thrown Exception into a 400.
		//
		// VTODOs are a separate matter and are not written here: a task carries
		// DUE and no DTSTART, and is correct that way.
		$dtstart = $this->veventCodec->requiredStart(data: $data);
		$calendarId = $this->findUserCalendar()['id'];

		$uid = strtoupper(bin2hex(random_bytes(16)));
		$calendarData = $this->veventCodec->calendarData(
			uid: $uid,
			dtstart: $dtstart,
			data: $data,
			link: ['registerId' => $registerId, 'schemaId' => $schemaId, 'objectUuid' => $objectUuid, 'objectTitle' => $objectTitle]
		);
		$uri = $uid . '.ics';

		$this->calDavBackend->createCalendarObject($calendarId, $uri, $calendarData);

		return $this->veventToArray(calendarData: $calendarData, calendarId: (string)$calendarId, uri: $uri);
	}//end createEvent()

	/**
	 * Link an existing calendar event to an object by adding X-OPENREGISTER-* properties.
	 *
	 * @param int $calendarId The calendar ID
	 * @param string $eventUri The event URI
	 * @param int $registerId The register ID
	 * @param int $schemaId The schema ID
	 * @param string $objectUuid The object UUID
	 *
	 * @return array|null The updated event
	 *
	 * @throws Exception If the event is not found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function linkEvent(
		int $calendarId,
		string $eventUri,
		int $registerId,
		int $schemaId,
		string $objectUuid,
	): ?array {
		$vcalendar = $this->readVevent(calendarId: $calendarId, eventUri: $eventUri);
		$vevent = $this->veventOf(document: $vcalendar);
		$vevent->add('X-OPENREGISTER-REGISTER', (string)$registerId);
		$vevent->add('X-OPENREGISTER-SCHEMA', (string)$schemaId);
		$vevent->add('X-OPENREGISTER-OBJECT', $objectUuid);

		$calendarData = $vcalendar->serialize();
		$this->calDavBackend->updateCalendarObject($calendarId, $eventUri, $calendarData);

		return $this->veventToArray(calendarData: $calendarData, calendarId: (string)$calendarId, uri: $eventUri);
	}//end linkEvent()

	/**
	 * Load a stored calendar object that must hold a VEVENT.
	 *
	 * @param int    $calendarId The calendar ID.
	 * @param string $eventUri   The event URI.
	 *
	 * @return Document The parsed calendar object.
	 *
	 * @throws Exception If the event is not found or is not a VEVENT.
	 */
	private function readVevent(int $calendarId, string $eventUri): Document {
		$existing = $this->calDavBackend->getCalendarObject($calendarId, $eventUri);
		if ($existing === null) {
			throw new Exception('Calendar event not found');
		}

		$vcalendar = Reader::read($existing['calendardata']);
		if ($this->veventOf(document: $vcalendar) === null) {
			throw new Exception('Calendar object is not a VEVENT');
		}

		return $vcalendar;
	}//end readVevent()

	/**
	 * Refuse an event that is not one of the caller's events on the given object.
	 *
	 * @param string $objectUuid The object the caller addressed.
	 * @param string $calendarId The calendar the caller named.
	 * @param string $eventUri   The event the caller named.
	 *
	 * @return void
	 *
	 * @throws Exception With code 404 when it is not.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function requireEventOf(string $objectUuid, string $calendarId, string $eventUri): void {
		foreach ($this->getEventsForObject(objectUuid: $objectUuid) as $event) {
			if ((string)$event['calendarId'] === $calendarId && $event['id'] === $eventUri) {
				return;
			}
		}

		throw new Exception('Calendar event not found', 404);
	}//end requireEventOf()

	/**
	 * Unlink an event from an object (remove X-OPENREGISTER-* properties).
	 *
	 * @param string $calendarId The calendar ID
	 * @param string $eventUri The event URI
	 *
	 * @return void
	 *
	 * @throws Exception If the event is not found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function unlinkEvent(string $calendarId, string $eventUri): void {
		$calendarIdInt = (int)$calendarId;
		$vcalendar = $this->readVevent(calendarId: $calendarIdInt, eventUri: $eventUri);
		$this->stripOpenRegisterLink(vevent: $this->veventOf(document: $vcalendar));

		$this->calDavBackend->updateCalendarObject($calendarIdInt, $eventUri, $vcalendar->serialize());
	}//end unlinkEvent()

	/**
	 * Remove the X-OPENREGISTER-* properties and every LINK mentioning openregister from a VEVENT.
	 *
	 * @param mixed $vevent The VEVENT component.
	 *
	 * @return void
	 */
	private function stripOpenRegisterLink(mixed $vevent): void {
		unset($vevent->{'X-OPENREGISTER-REGISTER'});
		unset($vevent->{'X-OPENREGISTER-SCHEMA'});
		unset($vevent->{'X-OPENREGISTER-OBJECT'});

		foreach ($vevent->select('LINK') as $link) {
			if (strpos((string)$link, 'openregister') !== false) {
				$vevent->remove($link);
			}
		}
	}//end stripOpenRegisterLink()

	/**
	 * Delete an event from the calendar outright.
	 *
	 * The counterpart to {@see unlinkEvent()}, and genuinely different from it:
	 * unlink strips the OpenRegister properties and leaves the meeting on the
	 * user's calendar, this removes the meeting.
	 *
	 * The distinction had been lost. `CalendarEventsController::destroy()`, the
	 * endpoint behind the leaf's "Delete meeting" row action, called
	 * `unlinkEvent()` and answered `{"success": true}`, so cancelling a hearing
	 * left the hearing on everybody's calendar while the case confirmed the
	 * deletion. The two row actions did the same thing.
	 *
	 * @param string $calendarId The calendar ID
	 * @param string $eventUri The event URI
	 *
	 * @return void
	 *
	 * @throws Exception If the event is not found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function deleteEvent(string $calendarId, string $eventUri): void {
		$calendarIdInt = (int)$calendarId;
		$this->refuseSomebodyElsesCalendar(calendarId: $calendarIdInt);

		$existing = $this->calDavBackend->getCalendarObject($calendarIdInt, $eventUri);

		if ($existing === null) {
			throw new Exception('Calendar event not found');
		}

		$this->calDavBackend->deleteCalendarObject($calendarIdInt, $eventUri);
	}//end deleteEvent()

	/**
	 * Refuse a calendar the session user does not own.
	 *
	 * The id comes from a link row any reader of the object can see, so holding
	 * it says nothing about the calendar.
	 *
	 * @param int $calendarId The calendar id.
	 *
	 * @return void
	 *
	 * @throws Exception With code 404 when it is not the caller's.
	 */
	private function refuseSomebodyElsesCalendar(int $calendarId): void {
		$user = $this->userSession->getUser();
		$calendar = $this->calDavBackend->getCalendarById($calendarId);
		if ($user === null || ($calendar['principaluri'] ?? null) !== 'principals/users/' . $user->getUID()) {
			throw new Exception('Calendar event not found', 404);
		}
	}//end refuseSomebodyElsesCalendar()

	/**
	 * Unlink all events for an object (used during cleanup).
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function unlinkEventsForObject(string $objectUuid): void {
		$events = $this->getEventsForObject(objectUuid: $objectUuid);

		foreach ($events as $event) {
			try {
				$this->unlinkEvent(calendarId: $event['calendarId'], eventUri: $event['id']);
			} catch (Exception $e) {
				$this->logger->warning(
					'Failed to unlink event ' . $event['id'] . ' from object ' . $objectUuid . ': ' . $e->getMessage()
				);
			}
		}
	}//end unlinkEventsForObject()

	/**
	 * Find the calendar OpenRegister should target for the current user.
	 *
	 * Resolution order:
	 *   1. The URI pinned in the user's IConfig (`openregister`/`events_calendar_uri`).
	 *      If present AND the underlying calendar still exists AND supports
	 *      VEVENT, that calendar is returned. This guarantees that a write
	 *      via {@see createEvent()} and a subsequent read via
	 *      {@see getEventsForObject()} always touch the same calendar even
	 *      when `CalDavBackend::getCalendarsForUser()` returns rows in a
	 *      different order across calls.
	 *   2. Fallback to the first VEVENT-supporting calendar in the list and
	 *      persist its URI as the pin for future calls. A user's `personal`
	 *      calendar is preferred when present so the pin lands on a sensible
	 *      default rather than on whichever VEVENT calendar happens to be
	 *      first (e.g. `contact_birthdays`).
	 *
	 * The pin is stored under `openregister`/`events_calendar_uri` and can
	 * be reset by clearing that user-value if a user wants OR to retarget
	 * (e.g. via `occ user:setting … --delete`).
	 *
	 * @return array Calendar data with 'id' and 'uri' keys
	 *
	 * @throws Exception If no user or calendar found
	 */
	private function findUserCalendar(): array {
		$userId = $this->currentUserId();
		$calendars = $this->calDavBackend->getCalendarsForUser('principals/users/' . $userId);

		return $this->calendarSelector->select(userId: $userId, calendars: $calendars);
	}//end findUserCalendar()

	/**
	 * The UID of the session user.
	 *
	 * @return string The user id.
	 *
	 * @throws Exception If no user is logged in.
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Parse a VEVENT iCalendar string into a JSON-friendly array.
	 *
	 * @param string $calendarData The raw iCalendar string
	 * @param string $calendarId The calendar ID
	 * @param string $uri The calendar object URI
	 *
	 * @return array|null Event array or null if not a VEVENT
	 */
	private function veventToArray(string $calendarData, string $calendarId, string $uri): ?array {
		$vevent = $this->veventOf(document: Reader::read($calendarData));
		if ($vevent === null) {
			return null;
		}

		return $this->veventCodec->eventArray(vevent: $vevent, calendarId: $calendarId, uri: $uri);
	}//end veventToArray()

	/**
	 * The first VEVENT of a parsed iCalendar document, or null when it has none.
	 *
	 * @param Document $document The parsed document.
	 *
	 * @return mixed The VEVENT component.
	 */
	private function veventOf(Document $document): mixed {
		return $document->VEVENT;
	}//end veventOf()
}//end class
