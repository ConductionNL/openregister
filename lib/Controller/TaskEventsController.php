<?php

/**
 * The calendar leaf, anchored on a TASK instead of on an object.
 *
 * Why this exists at all. The calendar leaf was reachable only at
 * `/api/objects/{register}/{schema}/{id}/events`, so a task page could not
 * carry one: an engine task ({@see \OCA\OpenRegister\Db\Task}) has no
 * register and no schema, and the object route's first act is to resolve
 * both. A task with a due date is exactly the thing somebody wants in a
 * calendar, and it was the one subject that could not be put there.
 *
 * Why it is this small. The link layer was already anchor-agnostic.
 * {@see \OCA\OpenRegister\Service\CalendarLinkService::getLinkedEvents()}
 * reads by bare uuid, and the calendar provider
 * ({@see \OCA\OpenRegister\Service\Integration\Providers\CalendarProvider})
 * already takes `$register, $schema, $objectId` and ignores the first two,
 * saying so in its own docblock. So a task-anchored event is the same row
 * in `openregister_calendar_links` as an object-anchored one, written and
 * read by the same service. Nothing about event handling is reimplemented
 * here, and nothing may be.
 *
 * The verb set mirrors {@see CalendarEventsController} exactly: index,
 * create, link, unlink, destroy. It is deliberately NOT index/create/
 * update/destroy, because there is no event UPDATE anywhere behind the
 * object leaf either. Neither `CalendarLinkService` nor
 * {@see \OCA\OpenRegister\Service\CalendarEventService} has a method that
 * rewrites a VEVENT, so an `update` verb here would have to grow its own
 * event handling, which is the one thing this controller must not do. The
 * pair that DOES exist, `link` and `unlink`, is what a task page needs
 * anyway: attach the meeting that already exists, detach it later.
 *
 * 🔴 404, NEVER 403, for a task the caller may not read, the same decision
 * {@see TaskController::show()} makes. A 403 here would confirm that a
 * guessed uuid names a real task, undoing on the leaf what the task surface
 * closed. And unlike the object leaf, a `register`/`schema` mismatch cannot
 * do that confirming for us: the only identifier in the URL is the task's.
 *
 * On the WRITE right: reads and writes are both gated on `mayRead()`. The
 * reasoning is set out once, in {@see TaskNotesController}, and both leaves
 * follow it so a task cannot be annotated by one audience and scheduled by
 * another.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * REST surface for the calendar leaf on a task.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller mediates
 * between HTTP, the two calendar services the object leaf already pairs,
 * and the task resolution plus its authorization. Splitting the guard out
 * would put the 404 decision in two places, which is how a leaf ends up
 * answering differently from its neighbour.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskEventsController extends Controller {

	/**
	 * The body every refusal carries, whether the task is absent or unreadable.
	 *
	 * @var array<string, string>
	 */
	private const NOT_FOUND = ['error' => 'No such task'];

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param TaskService $tasks Resolves a task by uuid.
	 * @param TaskAuthorizationService $authorization Read-visibility decisions.
	 * @param CalendarLinkService $links The link-table layer, uuid-anchored.
	 * @param CalendarEventService $events The VEVENT layer, for the legacy
	 *                                     X-OPENREGISTER-* strip on destroy.
	 * @param IUserSession $userSession Names the acting identity.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TaskService $tasks,
		private readonly TaskAuthorizationService $authorization,
		private readonly CalendarLinkService $links,
		private readonly CalendarEventService $events,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List the calendar events linked to a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The events and their count; 404 when the task is
	 *                      absent OR invisible.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $uuid): JSONResponse {
		$task = $this->readableTask(uuid: $uuid);
		if ($task === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		try {
			$events = $this->links->getLinkedEvents((string)$task->getUuid());
		} catch (Exception $failure) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['results' => $events, 'total' => count($events)]);
	}//end index()

	/**
	 * Create a calendar event and link it to a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The link row; 404 when the task is absent OR
	 *                      invisible, 400 when the summary is missing.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(string $uuid): JSONResponse {
		$task = $this->readableTask(uuid: $uuid);
		if ($task === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		$data = $this->request->getParams();
		if (trim((string)($data['summary'] ?? '')) === '') {
			return new JSONResponse(['error' => 'Event summary is required'], Http::STATUS_BAD_REQUEST);
		}

		// The title the event carries into the calendar. A task always has a
		// uuid and may have no title, so the uuid is the fallback, exactly as
		// the object leaf falls back from the object's name.
		$data['objectTitle'] = ($task->getTitle() ?? (string)$task->getUuid());

		try {
			$link = $this->links->createAndLinkEvent(
				objectUuid: (string)$task->getUuid(),
				registerId: (int)$task->getRegisterId(),
				schemaId: (int)$task->getSchemaId(),
				eventData: $data
			);
		} catch (Exception $failure) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($link->jsonSerialize(), Http::STATUS_CREATED);
	}//end create()

	/**
	 * Link an existing calendar event to a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The link row; 404 when the task is absent OR
	 *                      invisible, 400 when the event is not named in full.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function link(string $uuid): JSONResponse {
		$task = $this->readableTask(uuid: $uuid);
		if ($task === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		$data = $this->request->getParams();
		$calendarUri = trim((string)($data['calendarUri'] ?? ''));
		$eventUid = trim((string)($data['eventUid'] ?? ''));

		if ($calendarUri === '' || $eventUid === '') {
			return new JSONResponse(['error' => 'calendarUri and eventUid are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$link = $this->links->linkEvent(
				objectUuid: (string)$task->getUuid(),
				registerId: (int)$task->getRegisterId(),
				schemaId: (int)$task->getSchemaId(),
				calendarUri: $calendarUri,
				eventUid: $eventUid
			);
		} catch (Exception $failure) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($link->jsonSerialize(), Http::STATUS_CREATED);
	}//end link()

	/**
	 * Unlink a calendar event from a task, leaving the VEVENT alone.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $eventUid The VEVENT uid.
	 *
	 * @return JSONResponse Confirmation; 404 when the task is absent OR
	 *                      invisible.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unlink(string $uuid, string $eventUid): JSONResponse {
		$task = $this->readableTask(uuid: $uuid);
		if ($task === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		try {
			$this->links->unlinkEvent(objectUuid: (string)$task->getUuid(), eventUid: $eventUid);
		} catch (Exception $failure) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true]);
	}//end unlink()

	/**
	 * Detach a calendar event from a task by its event URI.
	 *
	 * The legacy semantics the object leaf carries: the X-OPENREGISTER-*
	 * properties come off the VEVENT and the link row goes, but the VEVENT
	 * itself stays in the calendar. For link-only removal use `unlink`.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $eventId The event URI.
	 *
	 * @return JSONResponse Confirmation; 404 when the task is absent OR
	 *                      invisible, and 404 when no linked event carries
	 *                      that URI.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(string $uuid, string $eventId): JSONResponse {
		$task = $this->readableTask(uuid: $uuid);
		if ($task === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		try {
			$linked = $this->linkedEvent(taskUuid: (string)$task->getUuid(), eventId: $eventId);
			if ($linked === null) {
				return new JSONResponse(['error' => 'Event not found'], Http::STATUS_NOT_FOUND);
			}

			$this->events->unlinkEvent(calendarId: (string)$linked['calendarId'], eventUri: $eventId);

			if ($linked['uid'] !== null) {
				$this->links->unlinkEvent(
					objectUuid: (string)$task->getUuid(),
					eventUid: (string)$linked['uid']
				);
			}
		} catch (Exception $failure) {
			return new JSONResponse(['error' => $failure->getMessage()], Http::STATUS_BAD_REQUEST);
		}//end try

		return new JSONResponse(['success' => true]);
	}//end destroy()

	/**
	 * The linked event carrying this URI, as calendar id plus uid.
	 *
	 * Looked up through the task's OWN linked list rather than by event URI
	 * alone, which is the guard that keeps `destroy` from reaching an event
	 * that belongs to some other subject: an id the caller invents resolves
	 * to null and is answered 404, never unlinked.
	 *
	 * @param string $taskUuid The task uuid.
	 * @param string $eventId The event URI.
	 *
	 * @return array{calendarId: mixed, uid: string|null}|null The event, or null.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function linkedEvent(string $taskUuid, string $eventId): ?array {
		foreach ($this->links->getLinkedEvents($taskUuid) as $event) {
			if (($event['id'] ?? null) !== $eventId) {
				continue;
			}

			if (($event['calendarId'] ?? null) === null) {
				return null;
			}

			$uid = null;
			if (isset($event['uid']) === true) {
				$uid = (string)$event['uid'];
			}

			return ['calendarId' => $event['calendarId'], 'uid' => $uid];
		}

		return null;
	}//end linkedEvent()

	/**
	 * The task this request may act on, or null.
	 *
	 * Fail-closed on every path: absent, invisible and undeterminable all
	 * return null, and every caller turns null into the same 404.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return Task|null The readable task, or null.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function readableTask(string $uuid): ?Task {
		try {
			$task = $this->tasks->get(uuid: $uuid);
		} catch (Throwable) {
			return null;
		}

		if ($this->authorization->mayRead(task: $task, uid: $this->uid()) === false) {
			return null;
		}

		return $task;
	}//end readableTask()

	/**
	 * The acting identity, or null without a session.
	 *
	 * @return string|null The uid.
	 */
	private function uid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end uid()
}//end class
