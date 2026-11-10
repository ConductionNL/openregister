<?php

/**
 * CalendarEventsController
 *
 * REST controller for calendar event operations on OpenRegister objects.
 * Wraps the Tier-2 {@see CalendarLinkService} (additive link-table layer
 * over the existing CalendarEventService / X-OPENREGISTER-* properties)
 * plus picker source endpoints for the frontend CnCalendarEventPicker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use DateTime;
use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\CalendarEventsControllerServices;
use OCA\OpenRegister\Controller\Trait\ChecksObjectUpdateRight;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\CalendarPickerWindow;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\CalendarLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Service\LinkTarget;

/**
 * CalendarEventsController handles calendar event operations for objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarEventsController extends Controller {
	use ChecksObjectUpdateRight;

	/**
	 * Calendar event service (legacy X-OR-* CalDAV custom properties).
	 *
	 * @var ObjectCalendarEventsInterface
	 */
	private readonly ObjectCalendarEventsInterface $calendarEventService;

	/**
	 * Tier-2 calendar link service (additive link-table layer).
	 *
	 * @var CalendarLinkServiceInterface
	 */
	private readonly CalendarLinkServiceInterface $calendarLinkService;

	/**
	 * Object service for object validation.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * Answers the object update right
	 *
	 * @var PermissionHandlerInterface
	 */
	private readonly PermissionHandlerInterface $permissions;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request object
	 * @param CalendarEventsControllerServices $services The controller's collaborators.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		CalendarEventsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->permissions = $services->permissions;

		$this->calendarEventService = $services->calendarEventService;
		$this->calendarLinkService = $services->calendarLinkService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List all calendar events linked to a specific object.
	 *
	 * Reads the UNION of link-table rows and the legacy X-OR-* scan.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse JSON response with events
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function index(string $register, string $schema, string $id): JSONResponse {
		return $this->listEvents(ref: new ObjectRef(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * Create a new calendar event linked to an object.
	 *
	 * Writes BOTH the X-OR-* properties on the VEVENT AND a link-table row.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse JSON response with the created event
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function create(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->writing(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->createdEvent(object: $object));
	}//end create()

	/**
	 * Link an existing calendar event to an object.
	 *
	 * Writes ONLY a link-table row (we may not own the VEVENT). Accepts
	 * either `{calendarUri, eventUid}` (new Tier-2 shape) or
	 * `{calendarId, eventUri}` (legacy shape — translated to the new
	 * one against the user's calendar list for backward compatibility).
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return JSONResponse JSON response with the linked event row
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function link(string $register, string $schema, string $id): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->writing(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->linkedEvent(object: $object));
	}//end link()

	/**
	 * Unlink a calendar event from an object (Tier-2 link-only removal).
	 *
	 * Removes the link-table row; if `taggedWithXor=true`, also strips
	 * X-OPENREGISTER-* properties from the VEVENT. The VEVENT itself
	 * is preserved on the user's calendar.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 * @param string $eventUid The event UID
	 *
	 * @return JSONResponse JSON response confirming unlink
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function unlink(string $register, string $schema, string $id, string $eventUid): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->writing(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->unlinkedEvent(object: $object, eventUid: $eventUid));
	}//end unlink()

	/**
	 * Destroy (delete) a calendar event by URI.
	 *
	 * This destroys the underlying VEVENT (legacy semantics — strips
	 * X-OR-* and removes the LINK property; the VEVENT remains in the
	 * calendar but is no longer associated with any OR object). For
	 * link-only removal, use the `unlink` endpoint at
	 * `/events/{eventUid}/link`.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 * @param string $eventId The event URI
	 *
	 * @return JSONResponse JSON response confirming deletion
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function destroy(string $register, string $schema, string $id, string $eventId): JSONResponse {
		$ref = new ObjectRef(register: $register, schema: $schema, id: $id);

		return $this->destroying(ref: $ref, action: fn (ObjectEntity $object): JSONResponse => $this->deletedEvent(object: $object, eventId: $eventId));
	}//end destroy()

	/**
	 * The calendar events linked to an object: 404 when it does not exist,
	 * 500 with the message on any other failure.
	 *
	 * @param ObjectRef $ref The register, schema and object id
	 *
	 * @return JSONResponse The events and their total
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function listEvents(ObjectRef $ref): JSONResponse {
		try {
			$object = $this->validateObject(ref: $ref);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$events = $this->calendarLinkService->getLinkedEvents($object->getUuid());

			return new JSONResponse(['results' => $events, 'total' => count($events)]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}
	}//end listEvents()

	/**
	 * Run a write on an object the caller may update; any failure but a
	 * missing object is a 400 with its message.
	 *
	 * @param ObjectRef $ref    The register, schema and object id
	 * @param callable  $action The write, given the object
	 *
	 * @return JSONResponse The write's response, 404, 403 or 400
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function writing(ObjectRef $ref, callable $action): JSONResponse {
		try {
			return $this->onWritableObject(ref: $ref, action: $action);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}
	}//end writing()

	/**
	 * Run a delete on an object the caller may update; a failure carrying
	 * code 404 is a 404, any other a 400 with its message.
	 *
	 * @param ObjectRef $ref    The register, schema and object id
	 * @param callable  $action The delete, given the object
	 *
	 * @return JSONResponse The delete's response, 404, 403 or 400
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function destroying(ObjectRef $ref, callable $action): JSONResponse {
		try {
			return $this->onWritableObject(ref: $ref, action: $action);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			$status = 400;
			if ($e->getCode() === 404) {
				$status = 404;
			}

			return new JSONResponse(['error' => $e->getMessage()], $status);
		}
	}//end destroying()

	/**
	 * Run an action on an object that exists and that the caller may
	 * update: 404 when it is not found, 403 without the update right.
	 *
	 * @param ObjectRef $ref    The register, schema and object id
	 * @param callable  $action The action, given the object
	 *
	 * @return JSONResponse The action's response, 404 or 403
	 *
	 * @throws DoesNotExistException When no such object exists
	 * @throws Exception             When loading the object or the action fails
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function onWritableObject(ObjectRef $ref, callable $action): JSONResponse {
		$object = $this->validateObject(ref: $ref);
		if ($object === null) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		}

		if ($this->mayUpdate(object: $object) === false) {
			return new JSONResponse(['error' => 'You may not change this object, so you cannot change its events.'], 403);
		}

		return $action($object);
	}//end onWritableObject()

	/**
	 * Create an event from the request and link it to the object; the
	 * summary is required.
	 *
	 * @param ObjectEntity $object The object
	 *
	 * @return JSONResponse The link, 201, or 400 without a summary
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function createdEvent(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		if (empty($data['summary']) === true) {
			return new JSONResponse(['error' => 'Event summary is required'], 400);
		}

		$data['objectTitle'] = $object->getName() ?? $object->getUuid();
		$target = (new LinkTarget(objectUuid: $object->getUuid(), registerId: (int)$object->getRegister()))->inSchema(schemaId: (int)$object->getSchema());
		$link = $this->calendarLinkService->createLinkedEvent(target: $target, eventData: $data);

		return new JSONResponse($link->jsonSerialize(), 201);
	}//end createdEvent()

	/**
	 * Link the event the request names by calendarUri and eventUid to the object.
	 *
	 * @param ObjectEntity $object The object
	 *
	 * @return JSONResponse The link, 201, or 400 without calendarUri and eventUid
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function linkedEvent(ObjectEntity $object): JSONResponse {
		$data = $this->request->getParams();
		$calendarUri = (string)($data['calendarUri'] ?? '');
		$eventUid = (string)($data['eventUid'] ?? '');
		if ($calendarUri === '' || $eventUid === '') {
			return new JSONResponse(['error' => 'calendarUri and eventUid are required'], 400);
		}

		$link = $this->calendarLinkService->linkEvent(
			objectUuid: $object->getUuid(),
			registerId: (int)$object->getRegister(),
			schemaId: (int)$object->getSchema(),
			calendarUri: $calendarUri,
			eventUid: $eventUid
		);

		return new JSONResponse($link->jsonSerialize(), 201);
	}//end linkedEvent()

	/**
	 * Remove the link between the object and one event.
	 *
	 * @param ObjectEntity $object   The object
	 * @param string       $eventUid The event UID
	 *
	 * @return JSONResponse Success
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function unlinkedEvent(ObjectEntity $object, string $eventUid): JSONResponse {
		$this->calendarLinkService->unlinkEvent(objectUuid: $object->getUuid(), eventUid: $eventUid);

		return new JSONResponse(['success' => true]);
	}//end unlinkedEvent()

	/**
	 * Delete one of the object's events and its link-table row.
	 *
	 * Deletes the event, which is what the endpoint says it does: it used
	 * to call unlinkEvent() only, which left the meeting on the calendar,
	 * so "Delete meeting" and "Unlink" were the same operation.
	 *
	 * @param ObjectEntity $object  The object
	 * @param string       $eventId The event URI
	 *
	 * @return JSONResponse Success, or 404 when the object has no such event
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function deletedEvent(ObjectEntity $object, string $eventId): JSONResponse {
		$event = $this->linkedEventById(object: $object, eventId: $eventId);
		if ($event === null || $event['calendarId'] === null) {
			return new JSONResponse(['error' => 'Event not found'], 404);
		}

		$this->calendarEventService->deleteEvent(calendarId: (string)$event['calendarId'], eventUri: $eventId);
		if (($event['uid'] ?? null) !== null) {
			$this->calendarLinkService->unlinkEvent(objectUuid: $object->getUuid(), eventUid: (string)$event['uid']);
		}

		return new JSONResponse(['success' => true]);
	}//end deletedEvent()

	/**
	 * The calendar id and uid of the first of the object's linked events
	 * (link rows and legacy scan) with the given id.
	 *
	 * @param ObjectEntity $object  The object
	 * @param string       $eventId The event URI
	 *
	 * @return array{calendarId: mixed, uid: mixed}|null The event, or null when none has the id
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	private function linkedEventById(ObjectEntity $object, string $eventId): ?array {
		foreach ($this->calendarLinkService->getLinkedEvents($object->getUuid()) as $event) {
			if ($event['id'] === $eventId) {
				return ['calendarId' => $event['calendarId'], 'uid' => ($event['uid'] ?? null)];
			}
		}

		return null;
	}//end linkedEventById()

	/**
	 * List the user's VEVENT-supporting calendars (picker step 1).
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @no-admin-idor-exempt Session-scoped list: returns only the current user's VEVENT-capable calendars; no caller-supplied object id.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function listCalendars(): JSONResponse {
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
	 * @no-admin-idor-exempt Guarded downstream: CalendarLinkService resolves the caller-supplied calendarUri only against calendars
	 *   scoped to the session user's principal (getCalendarsForUser); a non-owned URI resolves to null and throws, never leaking
	 *   another user's calendar.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function listCalendarEvents(string $calendarUri): JSONResponse {
		try {
			$params = $this->request->getParams();
			$limit = (int)($params['limit'] ?? 100);
			$after = null;
			if (empty($params['after']) === false) {
				try {
					$after = new DateTime((string)$params['after']);
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
				window: new CalendarPickerWindow(after: $after, limit: $limit)
			);

			return new JSONResponse(['results' => $events, 'total' => count($events)]);
		} catch (Exception $e) {
			$status = 500;
			if ($e->getCode() === 404) {
				$status = 404;
			}

			return new JSONResponse(['error' => $e->getMessage()], $status);
		}//end try
	}//end listCalendarEvents()

	/**
	 * Validate that the object exists.
	 *
	 * @param ObjectRef $ref The register, schema and object id
	 *
	 * @return ObjectEntity|null The object or null
	 *
	 * @spec exclude Private helper: resolves an object from register/schema/id; REST contract is owned by
	 *              retrofit-2026-05-24-calendar-integration/tasks.md#task-1.
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(ObjectRef $ref): ?ObjectEntity {
		$this->objectService->setRegister($ref->register);
		$this->objectService->setSchema($ref->schema);
		$this->objectService->setObject($ref->id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
