<?php

/**
 * TaskService
 *
 * Service that wraps CalDAV VTODO operations for linking tasks to OpenRegister objects.
 * Tasks are stored as standard VTODO items in the user's Nextcloud calendar with
 * X-OPENREGISTER-* properties for linking and an RFC 9253 LINK property.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
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
 * TaskService wraps CalDAV VTODO operations for OpenRegister objects.
 *
 * Provides methods to create, list, update, and delete CalDAV tasks (VTODOs)
 * that are linked to OpenRegister objects via X-OPENREGISTER-* properties.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Task orchestration requires coordination across multiple services
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class TaskService
{

    /**
     * CalDAV backend for calendar operations.
     *
     * @var CalDavBackend
     */
    private readonly CalDavBackend $calDavBackend;

    /**
     * User session for getting current user.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Logger for error reporting.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param CalDavBackend   $calDavBackend CalDAV backend for VTODO operations
     * @param IUserSession    $userSession   User session for current user context
     * @param LoggerInterface $logger        Logger for error reporting
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
     * Get all tasks linked to a specific OpenRegister object.
     *
     * Loads all VTODOs from the user's calendars and filters by
     * X-OPENREGISTER-OBJECT matching the given object UUID.
     *
     * @param string $objectUuid The UUID of the OpenRegister object
     *
     * @return array Array of task arrays in JSON-friendly format
     *
     * @throws Exception If no user is logged in or no calendar found
     */
    public function getTasksForObject(string $objectUuid): array
    {
        $calendar   = $this->findUserCalendar();
        $calendarId = $calendar['id'];

        // Get all calendar objects from this calendar.
        $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarId);

        $tasks = [];
        foreach ($calendarObjects as $calendarObject) {
            // Load the full calendar data for each object.
            $fullObject = $this->calDavBackend->getCalendarObject($calendarId, $calendarObject['uri']);
            if ($fullObject === null || empty($fullObject['calendardata']) === true) {
                continue;
            }

            $calendarData = $fullObject['calendardata'];

            // Quick check: skip if this doesn't contain our object UUID.
            if (strpos($calendarData, $objectUuid) === false) {
                continue;
            }

            // Quick check: skip if this is not a VTODO.
            if (strpos($calendarData, 'VTODO') === false) {
                continue;
            }

            try {
                $taskArray = $this->vtodoToArray(
                    calendarData: $calendarData,
                    calendarId: (string) $calendarId,
                    uri: $calendarObject['uri']
                );

                // Only include tasks that match our object UUID.
                if ($taskArray !== null && $taskArray['objectUuid'] === $objectUuid) {
                    $tasks[] = $taskArray;
                }
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to parse calendar object: '.$e->getMessage(),
                    ['uri' => $calendarObject['uri']]
                );
            }
        }//end foreach

        return $tasks;
    }//end getTasksForObject()

    /**
     * Create a new CalDAV task linked to an OpenRegister object.
     *
     * Builds a VCALENDAR/VTODO string with X-OPENREGISTER-* properties and
     * an RFC 9253 LINK property, then stores it in the user's calendar.
     *
     * @param int    $registerId  The register ID to link
     * @param int    $schemaId    The schema ID to link
     * @param string $objectUuid  The object UUID to link
     * @param string $objectTitle The object title for the LINK label
     * @param array  $data        Task data: summary, description, priority, due, status
     *
     * @return array|null The created task in JSON-friendly format, or null if the calendar data was not a VTODO
     *
     * @throws Exception If no user is logged in or no calendar found
     */
    public function createTask(
        int $registerId,
        int $schemaId,
        string $objectUuid,
        string $objectTitle,
        array $data
    ): ?array {
        $calendar   = $this->findUserCalendar();
        $calendarId = $calendar['id'];

        $uid      = strtoupper(bin2hex(random_bytes(16)));
        $dtstamp  = gmdate('Ymd\THis\Z');
        $summary  = $this->escapeIcalText(text: $data['summary'] ?? 'Untitled task');
        $status   = strtoupper($data['status'] ?? 'NEEDS-ACTION');
        $priority = (int) ($data['priority'] ?? 0);

        // Build the VTODO lines.
        $lines   = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//OpenRegister//Tasks//EN';
        $lines[] = 'BEGIN:VTODO';
        $lines[] = 'UID:'.$uid;
        $lines[] = 'DTSTAMP:'.$dtstamp;
        $lines[] = 'SUMMARY:'.$summary;

        if (empty($data['description']) === false) {
            $lines[] = 'DESCRIPTION:'.$this->escapeIcalText(text: $data['description']);
        }

        $lines[] = 'STATUS:'.$status;
        $lines[] = 'PRIORITY:'.$priority;

        if (empty($data['due']) === false) {
            $dueDate = new DateTime($data['due']);
            $lines[] = 'DUE:'.$dueDate->format('Ymd\THis\Z');
        }

        // X-OPENREGISTER linking properties.
        $lines[] = 'X-OPENREGISTER-REGISTER:'.$registerId;
        $lines[] = 'X-OPENREGISTER-SCHEMA:'.$schemaId;
        $lines[] = 'X-OPENREGISTER-OBJECT:'.$objectUuid;

        // RFC 9253 LINK property.
        $linkLabel = $this->escapeIcalText(text: $objectTitle);
        $linkUri   = '/apps/openregister/api/objects/'.$registerId.'/'.$schemaId.'/'.$objectUuid;
        $lines[]   = 'LINK;LINKREL="related";LABEL="'.$linkLabel.'";VALUE=URI:'.$linkUri;

        $lines[] = 'END:VTODO';
        $lines[] = 'END:VCALENDAR';

        $calendarData = implode("\r\n", $lines)."\r\n";
        $uri          = $uid.'.ics';

        $this->calDavBackend->createCalendarObject($calendarId, $uri, $calendarData);

        return $this->vtodoToArray(calendarData: $calendarData, calendarId: (string) $calendarId, uri: $uri);
    }//end createTask()

    /**
     * Update an existing CalDAV task.
     *
     * Loads the existing VTODO, applies changes, and saves it back.
     *
     * @param string $calendarId The calendar ID containing the task
     * @param string $taskUri    The URI of the task to update
     * @param array  $data       Fields to update: summary, description, priority, due, status
     *
     * @return array|null The updated task in JSON-friendly format, or null if calendar data was not a VTODO
     *
     * @throws Exception If the task is not found or update fails
     */
    public function updateTask(string $calendarId, string $taskUri, array $data): ?array
    {
        $calendarIdInt = (int) $calendarId;
        $existing      = $this->calDavBackend->getCalendarObject($calendarIdInt, $taskUri);

        if ($existing === null) {
            throw new Exception('Task not found');
        }

        $vcalendar = Reader::read($existing['calendardata']);
        $vtodo     = $vcalendar->VTODO;

        if ($vtodo === null) {
            throw new Exception('Calendar object is not a VTODO');
        }

        // Update fields that are provided.
        if (isset($data['summary']) === true) {
            $vtodo->SUMMARY = $data['summary'];
        }

        if (isset($data['description']) === true) {
            $vtodo->DESCRIPTION = $data['description'];
        }

        if (isset($data['status']) === true) {
            $vtodo->STATUS = strtoupper($data['status']);

            // If completing, set COMPLETED timestamp.
            if (strtoupper($data['status']) === 'COMPLETED') {
                $vtodo->COMPLETED = gmdate('Ymd\THis\Z');
            }
        }

        if (isset($data['priority']) === true) {
            $vtodo->PRIORITY = (int) $data['priority'];
        }

        if (isset($data['due']) === true && empty($data['due']) === true) {
            unset($vtodo->DUE);
        } else if (isset($data['due']) === true) {
            $vtodo->DUE = new DateTime($data['due']);
        }

        // Update DTSTAMP.
        $vtodo->DTSTAMP = gmdate('Ymd\THis\Z');

        $calendarData = $vcalendar->serialize();
        $this->calDavBackend->updateCalendarObject($calendarIdInt, $taskUri, $calendarData);

        return $this->vtodoToArray(calendarData: $calendarData, calendarId: $calendarId, uri: $taskUri);
    }//end updateTask()

    /**
     * Delete a CalDAV task.
     *
     * @param string $calendarId The calendar ID containing the task
     * @param string $taskUri    The URI of the task to delete
     *
     * @return void
     *
     * @throws Exception If the task is not found or deletion fails
     */
    public function deleteTask(string $calendarId, string $taskUri): void
    {
        $calendarIdInt = (int) $calendarId;
        $existing      = $this->calDavBackend->getCalendarObject($calendarIdInt, $taskUri);

        if ($existing === null) {
            throw new Exception('Task not found');
        }

        $this->calDavBackend->deleteCalendarObject($calendarIdInt, $taskUri);
    }//end deleteTask()

    /**
     * Find the user's first VTODO-supporting calendar.
     *
     * Checks the user's calendars and returns the first one that
     * supports VTODO components.
     *
     * @return array Calendar data with 'id' and 'uri' keys
     *
     * @throws Exception If no user is logged in or no suitable calendar found
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
            // Check if this calendar supports VTODO.
            $components = $calendar['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'];
            if ($components !== null) {
                // The value can be a CalendarComponent set or a string.
                $supportsVtodo = false;

                if (is_object($components) === true && method_exists($components, 'getValue') === true) {
                    $componentValues = $components->getValue();
                    foreach ($componentValues as $comp) {
                        if (strtoupper($comp) === 'VTODO') {
                            $supportsVtodo = true;
                            break;
                        }
                    }
                } else if (is_string($components) === true) {
                    $supportsVtodo = stripos($components, 'VTODO') !== false;
                } else if (is_iterable($components) === true) {
                    // If components is an array or other iterable.
                    foreach ($components as $comp) {
                        if (is_string($comp) === true) {
                            $compName = $comp;
                        } else {
                            $compName = (string) $comp;
                        }

                        if (strtoupper($compName) === 'VTODO') {
                            $supportsVtodo = true;
                            break;
                        }
                    }
                }//end if

                if ($supportsVtodo === true) {
                    return [
                        'id'  => $calendar['id'],
                        'uri' => $calendar['uri'],
                    ];
                }
            }//end if
        }//end foreach

        // No VTODO calendar found — create one.
        $this->calDavBackend->createCalendar(
            $principal,
            'tasks',
            [
                '{DAV:}displayname'                                               => 'Tasks',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(
                    ['VTODO']
                ),
            ]
        );

        // Re-fetch to get the created calendar.
        $calendars = $this->calDavBackend->getCalendarsForUser($principal);
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === 'tasks') {
                return [
                    'id'  => $calendar['id'],
                    'uri' => $calendar['uri'],
                ];
            }
        }

        throw new Exception('Failed to create tasks calendar for user '.$user->getUID());
    }//end findUserCalendar()

    /**
     * Parse a VTODO iCalendar string into a JSON-friendly array.
     *
     * Extracts standard VTODO fields and X-OPENREGISTER-* properties
     * from the raw iCalendar data.
     *
     * @param string $calendarData The raw iCalendar string
     * @param string $calendarId   The calendar ID
     * @param string $uri          The calendar object URI
     *
     * @return array|null Task array or null if not a VTODO
     */
    private function vtodoToArray(string $calendarData, string $calendarId, string $uri): ?array
    {
        $vcalendar = Reader::read($calendarData);
        $vtodo     = $vcalendar->VTODO;

        if ($vtodo === null) {
            return null;
        }

        // Extract X-OPENREGISTER properties.
        $linkData = $this->extractOpenRegisterProperties(vtodo: $vtodo);

        // Extract standard VTODO fields.
        $fields = $this->extractVtodoFields(vtodo: $vtodo);

        return [
            'id'          => $uri,
            'uid'         => $fields['uid'],
            'calendarId'  => $calendarId,
            'summary'     => $fields['summary'],
            'description' => $fields['description'],
            'status'      => $fields['status'],
            'priority'    => $fields['priority'],
            'due'         => $fields['due'],
            'completed'   => $fields['completed'],
            'created'     => $fields['created'],
            'objectUuid'  => $linkData['objectUuid'],
            'registerId'  => $linkData['registerId'],
            'schemaId'    => $linkData['schemaId'],
        ];
    }//end vtodoToArray()

    /**
     * Extract X-OPENREGISTER-* properties from a VTODO component.
     *
     * @param mixed $vtodo The VTODO component from the parsed iCalendar.
     *
     * @return array{objectUuid: string|null, registerId: int|null, schemaId: int|null}
     */
    private function extractOpenRegisterProperties(mixed $vtodo): array
    {
        $objectUuid = null;
        $registerId = null;
        $schemaId   = null;

        if (isset($vtodo->{'X-OPENREGISTER-OBJECT'}) === true) {
            $objectUuid = (string) $vtodo->{'X-OPENREGISTER-OBJECT'};
        }

        if (isset($vtodo->{'X-OPENREGISTER-REGISTER'}) === true) {
            $registerId = (int) (string) $vtodo->{'X-OPENREGISTER-REGISTER'};
        }

        if (isset($vtodo->{'X-OPENREGISTER-SCHEMA'}) === true) {
            $schemaId = (int) (string) $vtodo->{'X-OPENREGISTER-SCHEMA'};
        }

        return [
            'objectUuid' => $objectUuid,
            'registerId' => $registerId,
            'schemaId'   => $schemaId,
        ];
    }//end extractOpenRegisterProperties()

    /**
     * Extract standard VTODO fields from a VTODO component.
     *
     * @param mixed $vtodo The VTODO component from the parsed iCalendar.
     *
     * @return array{uid: string|null, summary: string, description: string,
     *     status: string, priority: int, due: string|null,
     *     completed: string|null, created: string|null}
     */
    private function extractVtodoFields(mixed $vtodo): array
    {
        $due = null;
        if (isset($vtodo->DUE) === true) {
            $due = $vtodo->DUE->getDateTime()->format('c');
        }

        $completed = null;
        if (isset($vtodo->COMPLETED) === true) {
            $completed = $vtodo->COMPLETED->getDateTime()->format('c');
        }

        $created = null;
        if (isset($vtodo->CREATED) === true) {
            $created = $vtodo->CREATED->getDateTime()->format('c');
        }

        // Map STATUS to lowercase.
        $status = 'needs-action';
        if (isset($vtodo->STATUS) === true) {
            // Normalize: NEEDS-ACTION -> needs-action, IN-PROCESS -> in-process, etc.
            $status = strtolower((string) $vtodo->STATUS);
        }

        $taskUid         = null;
        $taskSummary     = '';
        $taskDescription = '';
        $taskPriority    = 0;

        if (isset($vtodo->UID) === true) {
            $taskUid = (string) $vtodo->UID;
        }

        if (isset($vtodo->SUMMARY) === true) {
            $taskSummary = (string) $vtodo->SUMMARY;
        }

        if (isset($vtodo->DESCRIPTION) === true) {
            $taskDescription = (string) $vtodo->DESCRIPTION;
        }

        if (isset($vtodo->PRIORITY) === true) {
            $taskPriority = (int) (string) $vtodo->PRIORITY;
        }

        return [
            'uid'         => $taskUid,
            'summary'     => $taskSummary,
            'description' => $taskDescription,
            'status'      => $status,
            'priority'    => $taskPriority,
            'due'         => $due,
            'completed'   => $completed,
            'created'     => $created,
        ];
    }//end extractVtodoFields()

    /**
     * Escape text for use in iCalendar property values.
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
        $text = str_replace(';', '\\;', $text);

        return $text;
    }//end escapeIcalText()
}//end class
