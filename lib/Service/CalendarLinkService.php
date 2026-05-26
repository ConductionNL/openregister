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
 * @spec openspec/changes/integration-calendar/tasks.md
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
use Sabre\VObject\Reader;
use Throwable;

/**
 * Wraps CalendarEventService and CalendarLinkMapper.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class CalendarLinkService
{
    /**
     * Constructor.
     *
     * @param CalendarLinkMapper   $linkMapper           Link-table mapper.
     * @param CalendarEventService $calendarEventService Legacy CalDAV-property service.
     * @param CalDavBackend        $calDavBackend        CalDAV backend for direct picker queries.
     * @param IUserSession         $userSession          User session.
     * @param LoggerInterface      $logger               Logger.
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
     * @param string $objectUuid  Object UUID.
     * @param int    $registerId  Register id.
     * @param int    $schemaId    Schema id.
     * @param string $calendarUri Calendar URI (per-user CalDAV path component).
     * @param string $eventUid    VEVENT UID.
     *
     * @return CalendarLink The persisted link row.
     *
     * @throws Exception When no user is logged in or the event cannot be located.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-1/tasks.md#task-1
     */
    public function linkEvent(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        string $calendarUri,
        string $eventUid
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
            throw new Exception('Calendar event '.$eventUid.' not found on calendar '.$calendarUri);
        }

        $link = new CalendarLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setCalendarUri($calendarUri);
        $link->setCalendarId((int) $located['calendarId']);
        $link->setEventUid($eventUid);
        $link->setEventUri((string) $located['eventUri']);
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
     * @param string              $objectUuid Object UUID.
     * @param int                 $registerId Register id.
     * @param int                 $schemaId   Schema id.
     * @param array<string,mixed> $eventData  Event data (summary, dtstart, dtend, location, ...).
     *
     * @return CalendarLink The persisted link row.
     *
     * @throws Exception On creation failure.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-1/tasks.md#task-1
     */
    public function createAndLinkEvent(
        string $objectUuid,
        int $registerId,
        int $schemaId,
        array $eventData
    ): CalendarLink {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $objectTitle = (string) ($eventData['objectTitle'] ?? $objectUuid);

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
        $calendarId  = isset($event['calendarId']) ? (int) $event['calendarId'] : 0;
        $calendarUri = $this->resolveCalendarUriForId(userId: $user->getUID(), calendarId: $calendarId);

        $dtstart = null;
        if (isset($event['dtstart']) === true && $event['dtstart'] !== null) {
            try {
                $dtstart = new DateTime((string) $event['dtstart']);
            } catch (Exception $e) {
                $dtstart = null;
            }
        }

        $dtend = null;
        if (isset($event['dtend']) === true && $event['dtend'] !== null) {
            try {
                $dtend = new DateTime((string) $event['dtend']);
            } catch (Exception $e) {
                $dtend = null;
            }
        }

        $link = new CalendarLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setSchemaId($schemaId);
        $link->setCalendarUri($calendarUri ?? '');
        $link->setCalendarId($calendarId);
        $link->setEventUid((string) ($event['uid'] ?? ''));
        $link->setEventUri((string) ($event['id'] ?? ''));
        $link->setSummary((string) ($event['summary'] ?? ''));
        $link->setDtstart($dtstart);
        $link->setDtend($dtend);
        $link->setLocation(isset($event['location']) ? (string) $event['location'] : null);
        $link->setLinkedBy($user->getUID());
        $link->setLinkedAt(new DateTime());
        $link->setTaggedWithXor(true);

        return $this->linkMapper->insert(entity: $link);
    }//end createAndLinkEvent()

    /**
     * Unlink an event from an object.
     *
     * Always removes the link-table row. If the row carried
     * `taggedWithXor=true`, additionally strips the X-OPENREGISTER-*
     * properties from the underlying VEVENT (best-effort; failures
     * are logged but do not abort the unlink).
     *
     * @param string $objectUuid Object UUID.
     * @param string $eventUid   VEVENT UID.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-1/tasks.md#task-1
     */
    public function unlinkEvent(string $objectUuid, string $eventUid): void
    {
        $taggedWithXor = false;
        $calendarId    = null;
        $eventUri      = null;

        try {
            $existing      = $this->linkMapper->findByObjectAndEvent(
                objectUuid: $objectUuid,
                eventUid: $eventUid
            );
            $taggedWithXor = (bool) $existing->getTaggedWithXor();
            $calendarId    = $existing->getCalendarId();
            $eventUri      = $existing->getEventUri();
        } catch (DoesNotExistException $e) {
            // No link-table row — this is fine. We may still need to
            // unlink an X-OR-only event below.
        }

        $this->linkMapper->deleteByObjectAndEvent(objectUuid: $objectUuid, eventUid: $eventUid);

        // If the row had matching X-OR-* tags, strip them.
        if ($taggedWithXor === true && $calendarId !== null && $eventUri !== null) {
            try {
                $this->calendarEventService->unlinkEvent(
                    calendarId: (string) $calendarId,
                    eventUri: $eventUri
                );
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to strip X-OPENREGISTER properties for event '.$eventUid.': '.$e->getMessage()
                );
            }
        } else {
            // Fall back to the legacy X-OR-* scan: maybe this event
            // pre-dates the link table and only carries the custom
            // properties. Walk the user's events to find a match.
            try {
                $events = $this->calendarEventService->getEventsForObject(objectUuid: $objectUuid);
                foreach ($events as $event) {
                    if (($event['uid'] ?? null) === $eventUid) {
                        $this->calendarEventService->unlinkEvent(
                            calendarId: (string) $event['calendarId'],
                            eventUri: (string) $event['id']
                        );
                        break;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->info(
                    'Legacy X-OR unlink fallback failed for event '.$eventUid.': '.$e->getMessage()
                );
            }
        }//end if
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
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-1/tasks.md#task-1
     */
    public function getLinkedEvents(string $objectUuid): array
    {
        $merged = [];

        // 1. Link-table rows
        foreach ($this->linkMapper->findByObjectUuid(objectUuid: $objectUuid) as $link) {
            $key     = $link->getCalendarUri().'|'.$link->getEventUid();
            $payload = $link->jsonSerialize();
            $payload['source'] = 'link-table';
            $merged[$key]      = $payload;
        }

        // 2. Legacy X-OR-* scan (best-effort; degrade to empty list)
        try {
            $xorEvents = $this->calendarEventService->getEventsForObject(objectUuid: $objectUuid);
        } catch (Throwable $e) {
            $this->logger->info(
                'X-OR-* scan failed during getLinkedEvents: '.$e->getMessage()
            );
            $xorEvents = [];
        }

        $userId      = $this->userSession->getUser()?->getUID();
        $calendarMap = [];
        if ($userId !== null) {
            $calendarMap = $this->buildCalendarMapForUser(userId: $userId);
        }

        foreach ($xorEvents as $event) {
            $eventUid = (string) ($event['uid'] ?? '');
            if ($eventUid === '') {
                continue;
            }

            $calendarId  = (int) ($event['calendarId'] ?? 0);
            $calendarUri = $calendarMap[$calendarId] ?? '';
            $key         = $calendarUri.'|'.$eventUid;

            if (isset($merged[$key]) === true) {
                // Already present from link-table; mark as both.
                $merged[$key]['source'] = 'both';
                // Prefer the live X-OR-* values for free-text fields
                // (DB cache may be stale).
                foreach (['summary', 'dtstart', 'dtend', 'location', 'description', 'attendees', 'status'] as $field) {
                    if (isset($event[$field]) === true && $event[$field] !== null && $event[$field] !== '') {
                        $merged[$key][$field] = $event[$field];
                    }
                }

                continue;
            }

            // Try fallback dedupe by uid alone (calendar URI missing)
            $fallbackKey = null;
            foreach (array_keys($merged) as $existingKey) {
                if (str_ends_with($existingKey, '|'.$eventUid) === true) {
                    $fallbackKey = $existingKey;
                    break;
                }
            }

            if ($fallbackKey !== null) {
                $merged[$fallbackKey]['source'] = 'both';
                continue;
            }

            $payload           = $event;
            $payload['source'] = 'xor-only';
            $payload['calendarUri'] = $calendarUri;
            $merged[$key]           = $payload;
        }//end foreach

        return array_values($merged);
    }//end getLinkedEvents()

    /**
     * List the current user's VEVENT-supporting calendars.
     *
     * @return array<int,array<string,mixed>> Each entry has id, uri, displayName, color.
     *
     * @throws Exception When no user is logged in.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-1/tasks.md#task-1
     */
    public function getAvailableCalendars(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $principal = 'principals/users/'.$user->getUID();
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        $results = [];
        foreach ($calendars as $calendar) {
            if ($this->calendarSupportsVevent(calendar: $calendar) === false) {
                continue;
            }

            $results[] = [
                'id'          => (int) $calendar['id'],
                'uri'         => (string) $calendar['uri'],
                'displayName' => (string) ($calendar['{DAV:}displayname'] ?? $calendar['uri']),
                'color'       => isset($calendar['{http://apple.com/ns/ical/}calendar-color']) ? (string) $calendar['{http://apple.com/ns/ical/}calendar-color'] : null,
            ];
        }

        return $results;
    }//end getAvailableCalendars()

    /**
     * List recent events on a named calendar — picker step 2.
     *
     * @param string                 $calendarUri Calendar URI.
     * @param int|null               $limit       Max rows (default 100).
     * @param DateTimeInterface|null $after       Only include events with dtstart >= $after.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws Exception When no user, no calendar, or calendar URI is unknown.
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-1/tasks.md#task-1
     */
    public function getEventsForCalendar(string $calendarUri, ?int $limit=100, ?DateTimeInterface $after=null): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new Exception('No user logged in');
        }

        $principal = 'principals/users/'.$user->getUID();
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        $calendarId = null;
        foreach ($calendars as $calendar) {
            if (($calendar['uri'] ?? null) === $calendarUri
                && $this->calendarSupportsVevent(calendar: $calendar) === true
            ) {
                $calendarId = (int) $calendar['id'];
                break;
            }
        }

        if ($calendarId === null) {
            throw new Exception('Calendar '.$calendarUri.' not found for user');
        }

        $cutoff  = $after?->getTimestamp();
        $results = [];

        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $object) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $object['uri']);
            if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                continue;
            }

            $calendarData = $fullObject['calendardata'];
            if (strpos($calendarData, 'VEVENT') === false) {
                continue;
            }

            try {
                $vcalendar = Reader::read($calendarData);
                $vevent    = $vcalendar->VEVENT;
                if ($vevent === null) {
                    continue;
                }

                $uid = isset($vevent->UID) ? (string) $vevent->UID : null;
                if ($uid === null) {
                    continue;
                }

                $start = null;
                if (isset($vevent->DTSTART) === true) {
                    $start = $vevent->DTSTART->getDateTime();
                }

                if ($cutoff !== null && $start !== null && $start->getTimestamp() < $cutoff) {
                    continue;
                }

                $results[] = [
                    'uid'         => $uid,
                    'uri'         => $object['uri'],
                    'summary'     => isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : '',
                    'dtstart'     => $start?->format('c'),
                    'dtend'       => isset($vevent->DTEND) ? $vevent->DTEND->getDateTime()->format('c') : null,
                    'location'    => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                    'calendarId'  => $calendarId,
                    'calendarUri' => $calendarUri,
                ];
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to parse calendar event for picker: '.$e->getMessage(),
                    ['uri' => $object['uri']]
                );
            }//end try
        }//end foreach

        // Sort ascending by dtstart, nulls last.
        usort(
                $results,
                static function (array $a, array $b): int {
                    $ta = $a['dtstart'] !== null ? strtotime((string) $a['dtstart']) : PHP_INT_MAX;
                    $tb = $b['dtstart'] !== null ? strtotime((string) $b['dtstart']) : PHP_INT_MAX;
                    return $ta <=> $tb;
                }
                );

        if ($limit !== null && $limit > 0) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }//end getEventsForCalendar()

    /**
     * Locate a VEVENT on a named calendar and pull cacheable fields.
     *
     * @param string $userId      Current user id.
     * @param string $calendarUri Calendar URI.
     * @param string $eventUid    VEVENT UID.
     *
     * @return array{calendarId:int, eventUri:string, summary:?string, dtstart:?DateTime, dtend:?DateTime, location:?string}|null
     */
    private function locateEventOnCalendar(string $userId, string $calendarUri, string $eventUid): ?array
    {
        $principal = 'principals/users/'.$userId;
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        $calendarId = null;
        foreach ($calendars as $calendar) {
            if (($calendar['uri'] ?? null) === $calendarUri) {
                $calendarId = (int) $calendar['id'];
                break;
            }
        }

        if ($calendarId === null) {
            return null;
        }

        foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $object) {
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $object['uri']);
            if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                continue;
            }

            if (strpos($fullObject['calendardata'], $eventUid) === false) {
                continue;
            }

            try {
                $vcalendar = Reader::read($fullObject['calendardata']);
                $vevent    = $vcalendar->VEVENT;
                if ($vevent === null) {
                    continue;
                }

                if (isset($vevent->UID) === false || (string) $vevent->UID !== $eventUid) {
                    continue;
                }

                $dtstart = null;
                if (isset($vevent->DTSTART) === true) {
                    $dtstart = DateTime::createFromInterface($vevent->DTSTART->getDateTime());
                }

                $dtend = null;
                if (isset($vevent->DTEND) === true) {
                    $dtend = DateTime::createFromInterface($vevent->DTEND->getDateTime());
                }

                return [
                    'calendarId' => $calendarId,
                    'eventUri'   => (string) $object['uri'],
                    'summary'    => isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null,
                    'dtstart'    => $dtstart,
                    'dtend'      => $dtend,
                    'location'   => isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
                ];
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to parse VEVENT while locating event '.$eventUid.': '.$e->getMessage()
                );
            }//end try
        }//end foreach

        return null;
    }//end locateEventOnCalendar()

    /**
     * Build a {calendarId → calendarUri} map for the current user.
     *
     * @param string $userId Current user id.
     *
     * @return array<int,string>
     */
    private function buildCalendarMapForUser(string $userId): array
    {
        try {
            $principal = 'principals/users/'.$userId;
            $calendars = $this->calDavBackend->getCalendarsForUser($principal);
        } catch (Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($calendars as $calendar) {
            if (isset($calendar['id'], $calendar['uri']) === true) {
                $map[(int) $calendar['id']] = (string) $calendar['uri'];
            }
        }

        return $map;
    }//end buildCalendarMapForUser()

    /**
     * Resolve a calendar URI given its numeric id.
     *
     * @param string $userId     Current user id.
     * @param int    $calendarId Calendar numeric id.
     *
     * @return string|null
     */
    private function resolveCalendarUriForId(string $userId, int $calendarId): ?string
    {
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
}//end class
