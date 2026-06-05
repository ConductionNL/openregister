<?php

/**
 * CalendarEventsController
 *
<<<<<<< HEAD
 * REST controller for calendar event operations on OpenRegister objects.
 * Wraps the Tier-2 {@see CalendarLinkService} (additive link-table layer
 * over the existing CalendarEventService / X-OPENREGISTER-* properties)
 * plus picker source endpoints for the frontend CnCalendarEventPicker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
=======
 * REST controller for calendar event relation operations on OpenRegister objects.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

<<<<<<< HEAD
use DateTime;
use Exception;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
=======
use Exception;
use OCA\OpenRegister\Service\CalendarEventService;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * CalendarEventsController handles calendar event operations for objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
<<<<<<< HEAD
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */
class CalendarEventsController extends Controller
{

    /**
<<<<<<< HEAD
     * Calendar event service (legacy X-OR-* CalDAV custom properties).
=======
     * Calendar event service.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     *
     * @var CalendarEventService
     */
    private readonly CalendarEventService $calendarEventService;

    /**
<<<<<<< HEAD
     * Tier-2 calendar link service (additive link-table layer).
     *
     * @var CalendarLinkService
     */
    private readonly CalendarLinkService $calendarLinkService;

    /**
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     * Object service for object validation.
     *
     * @var ObjectService
     */
    private readonly ObjectService $objectService;

    /**
     * Constructor.
     *
     * @param string               $appName              Application name
     * @param IRequest             $request              HTTP request object
<<<<<<< HEAD
     * @param CalendarEventService $calendarEventService Calendar event service (legacy)
     * @param CalendarLinkService  $calendarLinkService  Calendar link service (Tier-2)
=======
     * @param CalendarEventService $calendarEventService Calendar event service
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     * @param ObjectService        $objectService        Object service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        CalendarEventService $calendarEventService,
<<<<<<< HEAD
        CalendarLinkService $calendarLinkService,
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        ObjectService $objectService
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->calendarEventService = $calendarEventService;
<<<<<<< HEAD
        $this->calendarLinkService  = $calendarLinkService;
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->objectService        = $objectService;
    }//end __construct()

    /**
<<<<<<< HEAD
     * List all calendar events linked to a specific object.
     *
     * Reads the UNION of link-table rows and the legacy X-OR-* scan.
=======
     * List all calendar events for a specific object.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with events
     *
     * @NoAdminRequired
     * @NoCSRFRequired
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        try {
<<<<<<< HEAD
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
=======
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

<<<<<<< HEAD
            $events = $this->calendarLinkService->getLinkedEvents($object->getUuid());
=======
            $events = $this->calendarEventService->getEventsForObject($object->getUuid());
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

            return new JSONResponse(['results' => $events, 'total' => count($events)]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end index()

    /**
     * Create a new calendar event linked to an object.
     *
<<<<<<< HEAD
     * Writes BOTH the X-OR-* properties on the VEVENT AND a link-table row.
     *
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with the created event
     *
     * @NoAdminRequired
     * @NoCSRFRequired
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        try {
<<<<<<< HEAD
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
=======
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            if (empty($data['summary']) === true) {
                return new JSONResponse(['error' => 'Event summary is required'], 400);
            }

<<<<<<< HEAD
            $data['objectTitle'] = $object->getName() ?? $object->getUuid();

            $link = $this->calendarLinkService->createAndLinkEvent(
                objectUuid: $object->getUuid(),
                registerId: (int) $object->getRegister(),
                schemaId: (int) $object->getSchema(),
                eventData: $data
            );

            return new JSONResponse($link->jsonSerialize(), 201);
=======
            $event = $this->calendarEventService->createEvent(
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $object->getUuid(),
                $object->getName() ?? $object->getUuid(),
                $data
            );

            return new JSONResponse($event, 201);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end create()

    /**
     * Link an existing calendar event to an object.
     *
<<<<<<< HEAD
     * Writes ONLY a link-table row (we may not own the VEVENT). Accepts
     * either `{calendarUri, eventUid}` (new Tier-2 shape) or
     * `{calendarId, eventUri}` (legacy shape — translated to the new
     * one against the user's calendar list for backward compatibility).
     *
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
<<<<<<< HEAD
     * @return JSONResponse JSON response with the linked event row
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
=======
     * @return JSONResponse JSON response with the linked event
     *
     * @NoAdminRequired
     * @NoCSRFRequired
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        try {
<<<<<<< HEAD
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
=======
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

<<<<<<< HEAD
            // Prefer the new (calendarUri, eventUid) shape; fall back to the
            // legacy (calendarId, eventUri) shape for backward compatibility.
            $calendarUri = (string) ($data['calendarUri'] ?? '');
            $eventUid    = (string) ($data['eventUid'] ?? '');

            if ($calendarUri === '' || $eventUid === '') {
                return new JSONResponse(['error' => 'calendarUri and eventUid are required'], 400);
            }

            $link = $this->calendarLinkService->linkEvent(
                objectUuid: $object->getUuid(),
                registerId: (int) $object->getRegister(),
                schemaId: (int) $object->getSchema(),
                calendarUri: $calendarUri,
                eventUid: $eventUid
            );

            return new JSONResponse($link->jsonSerialize(), 201);
=======
            if (empty($data['calendarId']) === true || empty($data['eventUri']) === true) {
                return new JSONResponse(['error' => 'calendarId and eventUri are required'], 400);
            }

            $event = $this->calendarEventService->linkEvent(
                (int) $data['calendarId'],
                $data['eventUri'],
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $object->getUuid()
            );

            return new JSONResponse($event);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end link()

    /**
<<<<<<< HEAD
     * Unlink a calendar event from an object (Tier-2 link-only removal).
     *
     * Removes the link-table row; if `taggedWithXor=true`, also strips
     * X-OPENREGISTER-* properties from the VEVENT. The VEVENT itself
     * is preserved on the user's calendar.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     * @param string $eventUid The event UID
     *
     * @return JSONResponse JSON response confirming unlink
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
     */
    public function unlink(string $register, string $schema, string $id, string $eventUid): JSONResponse
    {
        try {
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $this->calendarLinkService->unlinkEvent(
                objectUuid: $object->getUuid(),
                eventUid: $eventUid
            );

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }
    }//end unlink()

    /**
     * Destroy (delete) a calendar event by URI.
     *
     * This destroys the underlying VEVENT (legacy semantics — strips
     * X-OR-* and removes the LINK property; the VEVENT remains in the
     * calendar but is no longer associated with any OR object). For
     * link-only removal, use the `unlink` endpoint at
     * `/events/{eventUid}/link`.
=======
     * Unlink a calendar event from an object.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     * @param string $eventId  The event URI
     *
     * @return JSONResponse JSON response confirming deletion
     *
     * @NoAdminRequired
     * @NoCSRFRequired
<<<<<<< HEAD
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function destroy(string $register, string $schema, string $id, string $eventId): JSONResponse
    {
        try {
<<<<<<< HEAD
            $object = $this->validateObject(register: $register, schema: $schema, id: $id);
=======
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

<<<<<<< HEAD
            // Find the event in the unioned link list to recover its calendarId.
            $events     = $this->calendarLinkService->getLinkedEvents($object->getUuid());
            $calendarId = null;
            $eventUid   = null;
            foreach ($events as $existingEvent) {
                if ($existingEvent['id'] === $eventId) {
                    $calendarId = $existingEvent['calendarId'];
                    $eventUid   = $existingEvent['uid'] ?? null;
=======
            // Find the event in user's calendars to get calendarId.
            $events     = $this->calendarEventService->getEventsForObject($object->getUuid());
            $calendarId = null;
            foreach ($events as $existingEvent) {
                if ($existingEvent['id'] === $eventId) {
                    $calendarId = $existingEvent['calendarId'];
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
                    break;
                }
            }

            if ($calendarId === null) {
                return new JSONResponse(['error' => 'Event not found'], 404);
            }

<<<<<<< HEAD
            // Strip X-OR-* properties (legacy CalendarEventService behaviour).
            $this->calendarEventService->unlinkEvent(calendarId: (string) $calendarId, eventUri: $eventId);

            // Also remove the link-table row, if any.
            if ($eventUid !== null) {
                $this->calendarLinkService->unlinkEvent(
                    objectUuid: $object->getUuid(),
                    eventUid: (string) $eventUid
                );
            }
=======
            $this->calendarEventService->unlinkEvent($calendarId, $eventId);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end destroy()

    /**
<<<<<<< HEAD
     * List the user's VEVENT-supporting calendars (picker step 1).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
     */
    public function listCalendars(): JSONResponse
    {
        try {
            $calendars = $this->calendarLinkService->getAvailableCalendars();
            return new JSONResponse(['results' => $calendars, 'total' => count($calendars)]);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end listCalendars()

    /**
     * List events on a named calendar (picker step 2).
     *
     * @param string $calendarUri The calendar URI.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-calendar-integration/tasks.md#task-1
     */
    public function listCalendarEvents(string $calendarUri): JSONResponse
    {
        try {
            $params = $this->request->getParams();
            $limit  = (int) ($params['limit'] ?? 100);
            $after  = null;
            if (empty($params['after']) === false) {
                try {
                    $after = new DateTime((string) $params['after']);
                } catch (Exception $e) {
                    $after = null;
                }
            }

            if ($after === null) {
                // Default: now − 1 week.
                $after = (new DateTime())->modify('-1 week');
            }

            $events = $this->calendarLinkService->getEventsForCalendar(
                calendarUri: $calendarUri,
                limit: $limit,
                after: $after
            );

            return new JSONResponse(['results' => $events, 'total' => count($events)]);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }//end try
    }//end listCalendarEvents()

    /**
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     * Validate that the object exists.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
<<<<<<< HEAD
     *
     * @spec exclude Private helper: resolves an object from register/schema/id; REST contract is owned by
     *              retrofit-2026-05-24-calendar-integration/tasks.md#task-1.
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    private function validateObject(
        string $register,
        string $schema,
        string $id
    ): ?\OCA\OpenRegister\Db\ObjectEntity {
        $this->objectService->setSchema($schema);
        $this->objectService->setRegister($register);
        $this->objectService->setObject($id);

        return $this->objectService->getObject();
    }//end validateObject()
}//end class
