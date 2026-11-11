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
 * {@see \OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface} has a method that
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Controller\Support\TaskEventsControllerServices;
use OCA\OpenRegister\Controller\Support\TaskLeafGuard;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Service\CalendarEventKey;
use OCA\OpenRegister\Service\CalendarLinkServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Service\LinkTarget;

/**
 * REST surface for the calendar leaf on a task.
 *
 * The absent-or-invisible refusal is {@see TaskLeafGuard}'s, shared with
 * {@see TaskNotesController} so both leaves answer it identically.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskEventsController extends Controller {

	/**
	 * The shared task guard: resolve, check visibility, or answer 404.
	 *
	 * @var TaskLeafGuard
	 */
	private readonly TaskLeafGuard $guard;

	/**
	 * The link-table layer, uuid-anchored.
	 *
	 * @var CalendarLinkServiceInterface
	 */
	private readonly CalendarLinkServiceInterface $links;

	/**
	 * The VEVENT layer, for the legacy
	 *
	 * @var ObjectCalendarEventsInterface
	 */
	private readonly ObjectCalendarEventsInterface $events;

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param TaskEventsControllerServices $services The task events controller services.
	 * @param IUserSession $userSession Names the acting identity.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		TaskEventsControllerServices $services,
		IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->guard = new TaskLeafGuard(
			tasks: $services->tasks,
			authorization: $services->authorization,
			userSession: $userSession
		);
		$this->links = $services->links;
		$this->events = $services->events;

	}//end __construct()

	/**
	 * List the calendar events linked to a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The events and their count; 404 when the task is
	 *                      absent OR invisible.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $uuid): JSONResponse {
		return $this->guard->withReadableTask(uuid: $uuid, action: $this->listEvents(...));
	}//end index()

	/**
	 * Create a calendar event and link it to a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The link row; 404 when the task is absent OR
	 *                      invisible, 400 when the summary is missing.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(string $uuid): JSONResponse {
		return $this->guard->withReadableTask(uuid: $uuid, action: $this->createEvent(...));
	}//end create()

	/**
	 * Link an existing calendar event to a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The link row; 404 when the task is absent OR
	 *                      invisible, 400 when the event is not named in full.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function link(string $uuid): JSONResponse {
		return $this->guard->withReadableTask(uuid: $uuid, action: $this->linkEvent(...));
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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unlink(string $uuid, string $eventUid): JSONResponse {
		return $this->guard->withReadableTask(
			uuid: $uuid,
			action: fn (TaskInterface $task): JSONResponse => $this->succeeds(
				write: fn () => $this->links->unlinkEvent(objectUuid: (string)$task->getUuid(), eventUid: $eventUid)
			)
		);
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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(string $uuid, string $eventId): JSONResponse {
		return $this->guard->withReadableTask(
			uuid: $uuid,
			action: fn (TaskInterface $task): JSONResponse => $this->destroyEvent(task: $task, eventId: $eventId)
		);
	}//end destroy()

	/**
	 * The events linked to a readable task.
	 *
	 * @param TaskInterface $task The readable task.
	 *
	 * @return JSONResponse The events and their count, or 500 with the failure.
	 */
	private function listEvents(TaskInterface $task): JSONResponse {
		try {
			$events = $this->links->getLinkedEvents((string)$task->getUuid());
		} catch (Exception $failure) {
			return $this->failed(failure: $failure, status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['results' => $events, 'total' => count($events)]);
	}//end listEvents()

	/**
	 * Create an event from the request and link it to a readable task.
	 *
	 * @param TaskInterface $task The readable task.
	 *
	 * @return JSONResponse The link row, or 400.
	 */
	private function createEvent(TaskInterface $task): JSONResponse {
		$data = $this->request->getParams();
		if (trim((string)($data['summary'] ?? '')) === '') {
			return new JSONResponse(['error' => 'Event summary is required'], Http::STATUS_BAD_REQUEST);
		}

		// The title the event carries into the calendar. A task always has a
		// uuid and may have no title, so the uuid is the fallback, exactly as
		// the object leaf falls back from the object's name.
		$data['objectTitle'] = ($task->getTitle() ?? (string)$task->getUuid());

		return $this->linked(
			write: fn () => $this->links->createLinkedEvent(
				target: (new LinkTarget(objectUuid: (string)$task->getUuid(), registerId: (int)$task->getRegisterId()))
					->inSchema(schemaId: (int)$task->getSchemaId()),
				eventData: $data
			)
		);
	}//end createEvent()

	/**
	 * Link the event the request names to a readable task.
	 *
	 * @param TaskInterface $task The readable task.
	 *
	 * @return JSONResponse The link row, or 400.
	 */
	private function linkEvent(TaskInterface $task): JSONResponse {
		$named = $this->namedEvent();
		if ($named === null) {
			return new JSONResponse(['error' => 'calendarUri and eventUid are required'], Http::STATUS_BAD_REQUEST);
		}

		return $this->linked(
			write: fn () => $this->links->linkLocatedEvent(
				target: (new LinkTarget(objectUuid: (string)$task->getUuid(), registerId: (int)$task->getRegisterId()))
					->inSchema(schemaId: (int)$task->getSchemaId()),
				key: new CalendarEventKey(calendarUri: $named['calendarUri'], eventUid: $named['eventUid'])
			)
		);
	}//end linkEvent()

	/**
	 * The existing event the request names in full, trimmed, or null.
	 *
	 * @return array{calendarUri: string, eventUid: string}|null The event, or null when either part is blank.
	 */
	private function namedEvent(): ?array {
		$data = $this->request->getParams();
		$calendarUri = trim((string)($data['calendarUri'] ?? ''));
		$eventUid = trim((string)($data['eventUid'] ?? ''));

		if ($calendarUri === '' || $eventUid === '') {
			return null;
		}

		return ['calendarUri' => $calendarUri, 'eventUid' => $eventUid];
	}//end namedEvent()

	/**
	 * Delete a linked event of a readable task, then drop its link.
	 *
	 * @param TaskInterface $task    The readable task.
	 * @param string        $eventId The event URI.
	 *
	 * @return JSONResponse Confirmation, 404 for an event not linked here, or 400.
	 */
	private function destroyEvent(TaskInterface $task, string $eventId): JSONResponse {
		try {
			$linked = $this->linkedEvent(taskUuid: (string)$task->getUuid(), eventId: $eventId);
			if ($linked === null) {
				return new JSONResponse(['error' => 'Event not found'], Http::STATUS_NOT_FOUND);
			}

			// Delete, not unlink. This endpoint is the task-side twin of
			// CalendarEventsController::destroy() and carried the same fault:
			// unlinkEvent() strips the X-OPENREGISTER-* properties and leaves the
			// meeting on the calendar, so deleting a task's meeting removed the
			// link and nothing else while the response said success.
			$this->events->deleteEvent(calendarId: (string)$linked['calendarId'], eventUri: $eventId);
			$this->unlinkUid(task: $task, uid: $linked['uid']);
		} catch (Exception $failure) {
			return $this->failed(failure: $failure, status: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true]);
	}//end destroyEvent()

	/**
	 * Drop the link row of a deleted event, when the event carried a uid.
	 *
	 * @param TaskInterface $task The task.
	 * @param string|null   $uid  The event uid.
	 *
	 * @return void
	 */
	private function unlinkUid(TaskInterface $task, ?string $uid): void {
		if ($uid !== null) {
			$this->links->unlinkEvent(objectUuid: (string)$task->getUuid(), eventUid: $uid);
		}
	}//end unlinkUid()

	/**
	 * Run a link write and answer its row with 201, or 400 on failure.
	 *
	 * @param callable $write Returns the link entity.
	 *
	 * @return JSONResponse The response.
	 */
	private function linked(callable $write): JSONResponse {
		try {
			$link = $write();
		} catch (Exception $failure) {
			return $this->failed(failure: $failure, status: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($link->jsonSerialize(), Http::STATUS_CREATED);
	}//end linked()

	/**
	 * Run a write and answer success, or 400 on failure.
	 *
	 * @param callable $write The write.
	 *
	 * @return JSONResponse The response.
	 */
	private function succeeds(callable $write): JSONResponse {
		try {
			$write();
		} catch (Exception $failure) {
			return $this->failed(failure: $failure, status: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['success' => true]);
	}//end succeeds()

	/**
	 * A failure answered with its message.
	 *
	 * @param Exception $failure The failure.
	 * @param int       $status  The status.
	 *
	 * @return JSONResponse The response.
	 */
	private function failed(Exception $failure, int $status): JSONResponse {
		return new JSONResponse(['error' => $failure->getMessage()], $status);
	}//end failed()

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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	private function linkedEvent(string $taskUuid, string $eventId): ?array {
		foreach ($this->links->getLinkedEvents($taskUuid) as $event) {
			if (($event['id'] ?? null) === $eventId) {
				return $this->eventReference(event: $event);
			}
		}

		return null;
	}//end linkedEvent()

	/**
	 * The calendar id plus uid of a linked event; null when it names no calendar.
	 *
	 * @param array<string, mixed> $event The linked event.
	 *
	 * @return array{calendarId: mixed, uid: string|null}|null The reference, or null.
	 */
	private function eventReference(array $event): ?array {
		if (($event['calendarId'] ?? null) === null) {
			return null;
		}

		$uid = null;
		if (isset($event['uid']) === true) {
			$uid = (string)$event['uid'];
		}

		return ['calendarId' => $event['calendarId'], 'uid' => $uid];
	}//end eventReference()
}//end class
