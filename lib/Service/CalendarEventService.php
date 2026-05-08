<?php

/**
 * CalendarEventService
 *
 * Service that wraps CalDAV VEVENT operations for linking calendar events to OpenRegister objects.
 * Events are stored as standard VEVENT items in the user's Nextcloud calendar with
 * X-OPENREGISTER-* properties for linking and an RFC 9253 LINK property.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-25
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
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
     * Constructor.
     *
     * @param CalDavBackend   $calDavBackend CalDAV backend
     * @param IUserSession    $userSession   User session
     * @param LoggerInterface $logger        Logger
     *
     * @return void
     */
    public function __construct(
        CalDavBackend $calDavBackend,
        IUserSession $userSession,
        LoggerInterface $logger
    ) {
        $this->calDavBackend = $calDavBackend;
        $this->userSession   = $userSession;
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
                $eventArray = $this->veventToArray(calendarData: $calendarData, calendarId: (string) $calendarId, uri: $calendarObject['uri']);
                if ($eventArray !== null && $eventArray['objectUuid'] === $objectUuid) {
                    $events[] = $eventArray;
                }
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to parse calendar event: '.$e->getMessage(),
                    ['uri' => $calendarObject['uri']]
                );
            }
        }//end foreach

        return $events;
    }//end getEventsForObject()

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
     * Find the user's first VEVENT-supporting calendar.
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

        $principal = 'principals/users/'.$user->getUID();
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);

        foreach ($calendars as $calendar) {
            $components = $calendar['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'];
            if ($components !== null) {
                $supportsVevent = false;

                if (is_object($components) === true && method_exists($components, 'getValue') === true) {
                    foreach ($components->getValue() as $comp) {
                        if (strtoupper($comp) === 'VEVENT') {
                            $supportsVevent = true;
                            break;
                        }
                    }
                } else if (is_string($components) === true) {
                    $supportsVevent = stripos($components, 'VEVENT') !== false;
                } else if (is_iterable($components) === true) {
                    foreach ($components as $comp) {
                        if (strtoupper((string) $comp) === 'VEVENT') {
                            $supportsVevent = true;
                            break;
                        }
                    }
                }//end if

                if ($supportsVevent === true) {
                    return [
                        'id'  => $calendar['id'],
                        'uri' => $calendar['uri'],
                    ];
                }
            }//end if
        }//end foreach

        throw new Exception('No VEVENT-supporting calendar found for user '.$user->getUID());
    }//end findUserCalendar()

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

        return [
            'id'          => $uri,
            'uid'         => isset($vevent->UID) === true ? (string) $vevent->UID : null,
            'calendarId'  => $calendarId,
            'summary'     => isset($vevent->SUMMARY) === true ? (string) $vevent->SUMMARY : '',
            'dtstart'     => $dtstart,
            'dtend'       => $dtend,
            'location'    => isset($vevent->LOCATION) === true ? (string) $vevent->LOCATION : null,
            'description' => isset($vevent->DESCRIPTION) === true ? (string) $vevent->DESCRIPTION : '',
            'attendees'   => $attendees,
            'status'      => isset($vevent->STATUS) === true ? strtolower((string) $vevent->STATUS) : null,
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-25
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
