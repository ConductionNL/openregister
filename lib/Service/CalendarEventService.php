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

use DateTime;
use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Reader;

/**
 * CalendarEventService wraps CalDAV VEVENT operations for OpenRegister objects.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class CalendarEventService
{

    /**
     * NC app id used for IConfig user-value persistence.
     *
     * @var string
     */
    private const APP_NAME = 'openregister';

    /**
     * IConfig user-value key that pins the calendar URI chosen on the
     * first write. Subsequent reads honour this pin so read/write target
     * the same calendar regardless of `getCalendarsForUser()` ordering
     * — see {@see findUserCalendar()}.
     *
     * @var string
     */
    private const CONFIG_CALENDAR_URI = 'events_calendar_uri';

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
     * Config for user-scoped key/value persistence.
     *
     * @var IConfig
     */
    private readonly IConfig $config;

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
     * Constructor.
     *
     * @param CalDavBackend   $calDavBackend CalDAV backend
     * @param IUserSession    $userSession   User session
     * @param IConfig         $config        NC config (user-value store)
     * @param LoggerInterface $logger        Logger
     * @param IURLGenerator   $urlGenerator  URL generator for deep links
     *
     * @return void
     */
    public function __construct(
        CalDavBackend $calDavBackend,
        IUserSession $userSession,
        IConfig $config,
        LoggerInterface $logger,
        IURLGenerator $urlGenerator
    ) {
        $this->calDavBackend = $calDavBackend;
        $this->userSession   = $userSession;
        $this->urlGenerator  = $urlGenerator;
        $this->config        = $config;
        $this->logger        = $logger;
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
    public function getEventsForObject(string $objectUuid): array
    {
        $calendar   = $this->findUserCalendar();
        $calendarId = $calendar['id'];

        $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarId);

        $events = [];
        foreach ($calendarObjects as $calendarObject) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $calendarObject['uri']);
            if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                continue;
            }

            $calendarData = $fullObject['calendardata'];

            if (strpos($calendarData, $objectUuid) === false) {
                continue;
            }

            if (strpos($calendarData, 'VEVENT') === false) {
                continue;
            }

            try {
                $eventArray = $this->veventToArray(
                    calendarData: $calendarData,
                    calendarId: (string) $calendarId,
                    uri: $calendarObject['uri']
                );
                if ($eventArray !== null && $eventArray['objectUuid'] === $objectUuid) {
                    // Deep-link to the specific event in the Calendar app.
                    $deepLink = $this->buildEventDeepLink(
                        calendarUri: ($calendar['uri'] ?? null),
                        objectUri: $calendarObject['uri']
                    );
                    if ($deepLink !== null) {
                        $eventArray['url'] = $deepLink;
                    }

                    $events[] = $eventArray;
                }
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to parse calendar event: '.$e->getMessage(),
                    ['uri' => $calendarObject['uri']]
                );
            }//end try
        }//end foreach

        return $events;
    }//end getEventsForObject()

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
     * @param string|null $objectUri   The VEVENT object URI (.ics).
     *
     * @return string|null The deep-link URL, or null when not resolvable.
     */
    private function buildEventDeepLink(?string $calendarUri, ?string $objectUri): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null || $calendarUri === null || $calendarUri === ''
            || $objectUri === null || $objectUri === ''
        ) {
            return null;
        }

        try {
            $base = $this->urlGenerator->linkToRoute('calendar.view.index');
        } catch (\Throwable $e) {
            return null;
        }

        if ($base === '') {
            return null;
        }

        // The Calendar app decodes objectId to a DAV href and fetches it, so it
        // must carry the (webroot-aware) `/remote.php/dav/` prefix.
        $davPath = $this->urlGenerator->getWebroot().'/remote.php/dav/calendars/'.$user->getUID().'/'.$calendarUri.'/'.$objectUri;
        $token   = rawurlencode(base64_encode($davPath));

        return rtrim($base, '/').'/edit/'.$token;
    }//end buildEventDeepLink()

    /**
     * Get all VEVENTs across the acting user's VEVENT-supporting calendars.
     *
     * Read-only directory listing that mirrors {@see \OCA\OpenRegister\Service\TaskService::getAllUserTasks()}
     * for events: it enumerates every calendar the user owns (not just the pinned
     * one), skips non-VEVENT objects, and returns the parsed event arrays sorted by
     * start date (soonest first, undated last). Backs the `calendar-event-source`
     * ObjectSourceProvider.
     *
     * @param int $limit  Maximum number of events to return.
     * @param int $offset Number of events to skip.
     *
     * @return array{results: array<int, array<string, mixed>>, total: int} Events with total count.
     *
     * @throws Exception If no user is logged in.
     *
     * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
     */
    public function getAllUserEvents(int $limit=200, int $offset=0): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $principal = 'principals/users/'.$user->getUID();
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        $allEvents = [];
        foreach ($calendars as $calendar) {
            if ($this->calendarSupportsVevent(calendar: $calendar) === false) {
                continue;
            }

            $calendarId      = $calendar['id'];
            $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarId);

            foreach ($calendarObjects as $calendarObject) {
                $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $calendarObject['uri']);
                if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                    continue;
                }

                $calendarData = $fullObject['calendardata'];

                if (strpos($calendarData, 'VEVENT') === false) {
                    continue;
                }

                try {
                    $eventArray = $this->veventToArray(
                        calendarData: $calendarData,
                        calendarId: (string) $calendarId,
                        uri: $calendarObject['uri']
                    );
                    if ($eventArray !== null) {
                        $allEvents[] = $eventArray;
                    }
                } catch (Exception $e) {
                    $this->logger->warning(
                        'Failed to parse calendar event: '.$e->getMessage(),
                        ['uri' => $calendarObject['uri']]
                    );
                }
            }//end foreach
        }//end foreach

        // Sort by start date (soonest first, undated last).
        usort(
            array: $allEvents,
            callback: function ($a, $b) {
                $startA = ($a['dtstart'] ?? '9999-12-31');
                $startB = ($b['dtstart'] ?? '9999-12-31');
                return strcmp($startA, $startB);
            }
        );

        $total   = count($allEvents);
        $results = array_slice($allEvents, $offset, $limit);

        return [
            'results' => $results,
            'total'   => $total,
        ];
    }//end getAllUserEvents()

    /**
     * Create a new CalDAV event linked to an OpenRegister object.
     *
     * @param int    $registerId  The register ID
     * @param int    $schemaId    The schema ID
     * @param string $objectUuid  The object UUID
     * @param string $objectTitle The object title for the LINK label
     * @param array  $data        Event data: summary, dtstart, dtend, location, description, attendees
     *
     * @return array|null The created event in JSON-friendly format
     *
     * @throws Exception If no user or calendar found
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function createEvent(
        int $registerId,
        int $schemaId,
        string $objectUuid,
        string $objectTitle,
        array $data
    ): ?array {
        $calendar   = $this->findUserCalendar();
        $calendarId = $calendar['id'];

        $uid     = strtoupper(bin2hex(random_bytes(16)));
        $dtstamp = gmdate('Ymd\THis\Z');
        $summary = $this->escapeIcalText(text: $data['summary'] ?? 'Untitled event');

        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//OpenRegister//Events//EN';
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:'.$uid;
        $lines[] = 'DTSTAMP:'.$dtstamp;
        $lines[] = 'SUMMARY:'.$summary;

        if (empty($data['dtstart']) === false) {
            $dtstart = new DateTime($data['dtstart']);
            $lines[] = 'DTSTART:'.$dtstart->format('Ymd\THis\Z');
        }

        if (empty($data['dtend']) === false) {
            $dtend   = new DateTime($data['dtend']);
            $lines[] = 'DTEND:'.$dtend->format('Ymd\THis\Z');
        }

        if (empty($data['location']) === false) {
            $lines[] = 'LOCATION:'.$this->escapeIcalText(text: $data['location']);
        }

        if (empty($data['description']) === false) {
            $lines[] = 'DESCRIPTION:'.$this->escapeIcalText(text: $data['description']);
        }

        if (empty($data['attendees']) === false && is_array($data['attendees']) === true) {
            foreach ($data['attendees'] as $attendee) {
                $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:'.$attendee;
            }
        }

        // X-OPENREGISTER linking properties.
        $lines[] = 'X-OPENREGISTER-REGISTER:'.$registerId;
        $lines[] = 'X-OPENREGISTER-SCHEMA:'.$schemaId;
        $lines[] = 'X-OPENREGISTER-OBJECT:'.$objectUuid;

        // RFC 9253 LINK property.
        $linkLabel = $this->escapeIcalText(text: $objectTitle);
        $linkUri   = '/apps/openregister/api/objects/'.$registerId.'/'.$schemaId.'/'.$objectUuid;
        $lines[]   = 'LINK;LINKREL="related";LABEL="'.$linkLabel.'";VALUE=URI:'.$linkUri;

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        $calendarData = implode("\r\n", $lines)."\r\n";
        $uri          = $uid.'.ics';

        $this->calDavBackend->createCalendarObject($calendarId, $uri, $calendarData);

        return $this->veventToArray(calendarData: $calendarData, calendarId: (string) $calendarId, uri: $uri);
    }//end createEvent()

    /**
     * Link an existing calendar event to an object by adding X-OPENREGISTER-* properties.
     *
     * @param int    $calendarId The calendar ID
     * @param string $eventUri   The event URI
     * @param int    $registerId The register ID
     * @param int    $schemaId   The schema ID
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
        string $objectUuid
    ): ?array {
        $existing = $this->calDavBackend->getCalendarObject($calendarId, $eventUri);
        if ($existing === null) {
            throw new Exception('Calendar event not found');
        }

        $vcalendar = Reader::read($existing['calendardata']);
        $vevent    = $vcalendar->VEVENT;

        if ($vevent === null) {
            throw new Exception('Calendar object is not a VEVENT');
        }

        $vevent->add('X-OPENREGISTER-REGISTER', (string) $registerId);
        $vevent->add('X-OPENREGISTER-SCHEMA', (string) $schemaId);
        $vevent->add('X-OPENREGISTER-OBJECT', $objectUuid);

        $calendarData = $vcalendar->serialize();
        $this->calDavBackend->updateCalendarObject($calendarId, $eventUri, $calendarData);

        return $this->veventToArray(calendarData: $calendarData, calendarId: (string) $calendarId, uri: $eventUri);
    }//end linkEvent()

    /**
     * Unlink an event from an object (remove X-OPENREGISTER-* properties).
     *
     * @param string $calendarId The calendar ID
     * @param string $eventUri   The event URI
     *
     * @return void
     *
     * @throws Exception If the event is not found
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function unlinkEvent(string $calendarId, string $eventUri): void
    {
        $calendarIdInt = (int) $calendarId;
        $existing      = $this->calDavBackend->getCalendarObject($calendarIdInt, $eventUri);

        if ($existing === null) {
            throw new Exception('Calendar event not found');
        }

        $vcalendar = Reader::read($existing['calendardata']);
        $vevent    = $vcalendar->VEVENT;

        if ($vevent === null) {
            throw new Exception('Calendar object is not a VEVENT');
        }

        // Remove X-OPENREGISTER-* properties.
        unset($vevent->{'X-OPENREGISTER-REGISTER'});
        unset($vevent->{'X-OPENREGISTER-SCHEMA'});
        unset($vevent->{'X-OPENREGISTER-OBJECT'});

        // Remove LINK property with openregister.
        foreach ($vevent->select('LINK') as $link) {
            $value = (string) $link;
            if (strpos($value, 'openregister') !== false) {
                $vevent->remove($link);
            }
        }

        $calendarData = $vcalendar->serialize();
        $this->calDavBackend->updateCalendarObject($calendarIdInt, $eventUri, $calendarData);
    }//end unlinkEvent()

    /**
     * Unlink all events for an object (used during cleanup).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return void
     *
     * @spec openspec/specs/calendar-integration/spec.md
     */
    public function unlinkEventsForObject(string $objectUuid): void
    {
        $events = $this->getEventsForObject(objectUuid: $objectUuid);

        foreach ($events as $event) {
            try {
                $this->unlinkEvent(calendarId: $event['calendarId'], eventUri: $event['id']);
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to unlink event '.$event['id'].' from object '.$objectUuid.': '.$e->getMessage()
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
    private function findUserCalendar(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $userId    = $user->getUID();
        $principal = 'principals/users/'.$userId;
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        // Try the persisted URI first so write/read stay aligned.
        $pinnedUri = $this->config->getUserValue(
            $userId,
            self::APP_NAME,
            self::CONFIG_CALENDAR_URI,
            ''
        );
        if ($pinnedUri !== '') {
            foreach ($calendars as $calendar) {
                if (($calendar['uri'] ?? null) === $pinnedUri
                    && $this->calendarSupportsVevent(calendar: $calendar) === true
                ) {
                    return [
                        'id'  => $calendar['id'],
                        'uri' => $calendar['uri'],
                    ];
                }
            }

            // Pin is stale (calendar gone / no longer VEVENT). Fall through
            // to redetect + repin, and log so admins can see the migration.
            $this->logger->info(
                'Pinned calendar URI '.$pinnedUri.' no longer resolves for user '.$userId.'; reselecting'
            );
        }

        // Prefer `personal` when present so the pin lands on the user's
        // default calendar rather than on whichever calendar happens to
        // be first in the row order (`contact_birthdays`, …).
        $chosen = null;
        foreach ($calendars as $calendar) {
            if ($this->calendarSupportsVevent(calendar: $calendar) === false) {
                continue;
            }

            if (($calendar['uri'] ?? null) === 'personal') {
                $chosen = $calendar;
                break;
            }

            if ($chosen === null) {
                $chosen = $calendar;
            }
        }

        if ($chosen === null) {
            throw new Exception('No VEVENT-supporting calendar found for user '.$userId);
        }

        // Persist the pin so subsequent calls are deterministic.
        try {
            $this->config->setUserValue(
                $userId,
                self::APP_NAME,
                self::CONFIG_CALENDAR_URI,
                (string) $chosen['uri']
            );
        } catch (Exception $e) {
            // Best-effort: missing pin only means the next call repeats
            // this selection — not a fatal error for the current operation.
            $this->logger->warning(
                'Failed to persist calendar URI pin: '.$e->getMessage(),
                ['userId' => $userId, 'uri' => $chosen['uri'] ?? null]
            );
        }

        return [
            'id'  => $chosen['id'],
            'uri' => $chosen['uri'],
        ];
    }//end findUserCalendar()

    /**
     * Inspect a calendar row from CalDavBackend and decide whether it
     * supports VEVENT.
     *
     * @param array $calendar Row returned by CalDavBackend::getCalendarsForUser().
     *
     * @return bool
     */
    private function calendarSupportsVevent(array $calendar): bool
    {
        $components = $calendar['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
        if ($components === null) {
            return false;
        }

        if (is_object($components) === true && method_exists($components, 'getValue') === true) {
            foreach ($components->getValue() as $comp) {
                if (strtoupper((string) $comp) === 'VEVENT') {
                    return true;
                }
            }

            return false;
        }

        if (is_string($components) === true) {
            return stripos($components, 'VEVENT') !== false;
        }

        if (is_iterable($components) === true) {
            foreach ($components as $comp) {
                if (strtoupper((string) $comp) === 'VEVENT') {
                    return true;
                }
            }
        }

        return false;
    }//end calendarSupportsVevent()

    /**
     * Parse a VEVENT iCalendar string into a JSON-friendly array.
     *
     * @param string $calendarData The raw iCalendar string
     * @param string $calendarId   The calendar ID
     * @param string $uri          The calendar object URI
     *
     * @return array|null Event array or null if not a VEVENT
     */
    private function veventToArray(string $calendarData, string $calendarId, string $uri): ?array
    {
        $vcalendar = Reader::read($calendarData);
        $vevent    = $vcalendar->VEVENT;

        if ($vevent === null) {
            return null;
        }

        $linkData = $this->extractOpenRegisterProperties(vevent: $vevent);

        $dtstart = null;
        if (isset($vevent->DTSTART) === true) {
            $dtstart = $vevent->DTSTART->getDateTime()->format('c');
        }

        $dtend = null;
        if (isset($vevent->DTEND) === true) {
            $dtend = $vevent->DTEND->getDateTime()->format('c');
        }

        $attendees = [];
        if (isset($vevent->ATTENDEE) === true) {
            foreach ($vevent->ATTENDEE as $attendee) {
                $attendees[] = str_replace('mailto:', '', (string) $attendee);
            }
        }

        $uid = null;
        if (isset($vevent->UID) === true) {
            $uid = (string) $vevent->UID;
        }

        $summary = '';
        if (isset($vevent->SUMMARY) === true) {
            $summary = (string) $vevent->SUMMARY;
        }

        $location = null;
        if (isset($vevent->LOCATION) === true) {
            $location = (string) $vevent->LOCATION;
        }

        $description = '';
        if (isset($vevent->DESCRIPTION) === true) {
            $description = (string) $vevent->DESCRIPTION;
        }

        $status = null;
        if (isset($vevent->STATUS) === true) {
            $status = strtolower((string) $vevent->STATUS);
        }

        return [
            'id'          => $uri,
            'uid'         => $uid,
            'calendarId'  => $calendarId,
            'summary'     => $summary,
            'dtstart'     => $dtstart,
            'dtend'       => $dtend,
            'location'    => $location,
            'description' => $description,
            'attendees'   => $attendees,
            'status'      => $status,
            'objectUuid'  => $linkData['objectUuid'],
            'registerId'  => $linkData['registerId'],
            'schemaId'    => $linkData['schemaId'],
        ];
    }//end veventToArray()

    /**
     * Extract X-OPENREGISTER-* properties from a VEVENT component.
     *
     * @param mixed $vevent The VEVENT component.
     *
     * @return array{objectUuid: string|null, registerId: int|null, schemaId: int|null}
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-webhookeventlistener-must-extract-structured-payloads-from-all-event-types
     */
    private function extractOpenRegisterProperties(mixed $vevent): array
    {
        $objectUuid = null;
        $registerId = null;
        $schemaId   = null;

        if (isset($vevent->{'X-OPENREGISTER-OBJECT'}) === true) {
            $objectUuid = (string) $vevent->{'X-OPENREGISTER-OBJECT'};
        }

        if (isset($vevent->{'X-OPENREGISTER-REGISTER'}) === true) {
            $registerId = (int) (string) $vevent->{'X-OPENREGISTER-REGISTER'};
        }

        if (isset($vevent->{'X-OPENREGISTER-SCHEMA'}) === true) {
            $schemaId = (int) (string) $vevent->{'X-OPENREGISTER-SCHEMA'};
        }

        return [
            'objectUuid' => $objectUuid,
            'registerId' => $registerId,
            'schemaId'   => $schemaId,
        ];
    }//end extractOpenRegisterProperties()

    /**
     * Escape text for iCalendar property values.
     *
     * @param string $text The text to escape
     *
     * @return string The escaped text
     */
    private function escapeIcalText(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace(';', '\;', $text);

        return $text;
    }//end escapeIcalText()
}//end class
