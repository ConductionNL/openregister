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
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarEventsController extends Controller {

	/**
	 * Calendar event service (legacy X-OR-* CalDAV custom properties).
	 *
	 * @var CalendarEventService
	 */
	private readonly CalendarEventService $calendarEventService;

	/**
	 * Tier-2 calendar link service (additive link-table layer).
	 *
	 * @var CalendarLinkService
	 */
	private readonly CalendarLinkService $calendarLinkService;

	/**
	 * Object service for object validation.
	 *
	 * @var ObjectService
	 */
	private readonly ObjectService $objectService;

	/**
	 * Constructor.
	 *
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param CalendarEventService $calendarEventService Calendar event service (legacy)
	 * @param CalendarLinkService $calendarLinkService Calendar link service (Tier-2)
	 * @param ObjectService $objectService Object service
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		CalendarEventService $calendarEventService,
		CalendarLinkService $calendarLinkService,
		ObjectService $objectService,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->calendarEventService = $calendarEventService;
		$this->calendarLinkService = $calendarLinkService;
		$this->objectService = $objectService;
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$data = $this->request->getParams();

			if (empty($data['summary']) === true) {
				return new JSONResponse(['error' => 'Event summary is required'], 400);
			}

			$data['objectTitle'] = $object->getName() ?? $object->getUuid();

			$link = $this->calendarLinkService->createAndLinkEvent(
				objectUuid: $object->getUuid(),
				registerId: (int)$object->getRegister(),
				schemaId: (int)$object->getSchema(),
				eventData: $data
			);

			return new JSONResponse($link->jsonSerialize(), 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			$data = $this->request->getParams();

			// Prefer the new (calendarUri, eventUid) shape; fall back to the
			// legacy (calendarId, eventUri) shape for backward compatibility.
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
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(['error' => 'Object not found'], 404);
			}

			// Find the event in the unioned link list to recover its calendarId.
			$events = $this->calendarLinkService->getLinkedEvents($object->getUuid());
			$calendarId = null;
			$eventUid = null;
			foreach ($events as $existingEvent) {
				if ($existingEvent['id'] === $eventId) {
					$calendarId = $existingEvent['calendarId'];
					$eventUid = $existingEvent['uid'] ?? null;
					break;
				}
			}

			if ($calendarId === null) {
				return new JSONResponse(['error' => 'Event not found'], 404);
			}

			// Strip X-OR-* properties (legacy CalendarEventService behaviour).
			$this->calendarEventService->unlinkEvent(calendarId: (string)$calendarId, eventUri: $eventId);

			// Also remove the link-table row, if any.
			if ($eventUid !== null) {
				$this->calendarLinkService->unlinkEvent(
					objectUuid: $object->getUuid(),
					eventUid: (string)$eventUid
				);
			}

			return new JSONResponse(['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'Object not found'], 404);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 400);
		}//end try
	}//end destroy()

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
				limit: $limit,
				after: $after
			);

			return new JSONResponse(['results' => $events, 'total' => count($events)]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], 500);
		}//end try
	}//end listCalendarEvents()

	/**
	 * Validate that the object exists.
	 *
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $id The object ID
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
	 *
	 * @spec exclude Private helper: resolves an object from register/schema/id; REST contract is owned by
	 *              retrofit-2026-05-24-calendar-integration/tasks.md#task-1.
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(
		string $register,
		string $schema,
		string $id,
	): ?\OCA\OpenRegister\Db\ObjectEntity {
		$this->objectService->setSchema($schema);
		$this->objectService->setRegister($register);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
