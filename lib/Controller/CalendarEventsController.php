<?php

/**
 * CalendarEventsController
 *
 * REST controller for calendar event relation operations on OpenRegister objects.
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

use Exception;
use OCA\OpenRegister\Service\CalendarEventService;
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
 */
class CalendarEventsController extends Controller
{

    /**
     * Calendar event service.
     *
     * @var CalendarEventService
     */
    private readonly CalendarEventService $calendarEventService;

    /**
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
     * @param CalendarEventService $calendarEventService Calendar event service
     * @param ObjectService        $objectService        Object service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        CalendarEventService $calendarEventService,
        ObjectService $objectService
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->calendarEventService = $calendarEventService;
        $this->objectService        = $objectService;
    }//end __construct()

    /**
     * List all calendar events for a specific object.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with events
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $events = $this->calendarEventService->getEventsForObject($object->getUuid());

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
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with the created event
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

            if (empty($data['summary']) === true) {
                return new JSONResponse(['error' => 'Event summary is required'], 400);
            }

            $event = $this->calendarEventService->createEvent(
                (int) $object->getRegister(),
                (int) $object->getSchema(),
                $object->getUuid(),
                $object->getName() ?? $object->getUuid(),
                $data
            );

            return new JSONResponse($event, 201);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end create()

    /**
     * Link an existing calendar event to an object.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return JSONResponse JSON response with the linked event
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function link(string $register, string $schema, string $id): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            $data = $this->request->getParams();

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
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end link()

    /**
     * Unlink a calendar event from an object.
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
     */
    public function destroy(string $register, string $schema, string $id, string $eventId): JSONResponse
    {
        try {
            $object = $this->validateObject(object: $register, schema: $schema, schemaObject: $id);
            if ($object === null) {
                return new JSONResponse(['error' => 'Object not found'], 404);
            }

            // Find the event in user's calendars to get calendarId.
            $events     = $this->calendarEventService->getEventsForObject($object->getUuid());
            $calendarId = null;
            foreach ($events as $existingEvent) {
                if ($existingEvent['id'] === $eventId) {
                    $calendarId = $existingEvent['calendarId'];
                    break;
                }
            }

            if ($calendarId === null) {
                return new JSONResponse(['error' => 'Event not found'], 404);
            }

            $this->calendarEventService->unlinkEvent($calendarId, $eventId);

            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Object not found'], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 400);
        }//end try
    }//end destroy()

    /**
     * Validate that the object exists.
     *
     * @param string $register The register slug
     * @param string $schema   The schema slug
     * @param string $id       The object ID
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
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
