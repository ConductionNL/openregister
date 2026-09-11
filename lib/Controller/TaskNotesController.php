<?php

/**
 * The notes leaf, anchored on a TASK instead of on an object.
 *
 * Why this exists at all. The notes leaf was reachable only at
 * `/api/objects/{register}/{schema}/{id}/notes`, so a task page had nowhere
 * to hang a note: an engine task (`oc_openregister_tasks`,
 * {@see \OCA\OpenRegister\Db\Task}) is not an object, has no register and
 * no schema, and the object route's first act is to resolve all three.
 *
 * Why it is this small. The STORAGE was already anchor-agnostic.
 * {@see \OCA\OpenRegister\Service\NoteService} writes Nextcloud comments
 * with `object_type = 'openregister'` and `object_id = <uuid string>`; it
 * takes a bare uuid and asks nothing about what that uuid names. So a task
 * note and an object note are the same row in the same table, read back by
 * the same query. Nothing about notes is reimplemented here, and nothing
 * may be: the moment this controller grows its own note handling, a task
 * note and an object note stop being the same thing.
 *
 * What genuinely differs is the GUARD, and only the guard.
 * {@see NotesController} resolves an object through `ObjectService` and
 * answers 404 when it is absent. There is no object to resolve here, so the
 * gate is the task's own: resolve through
 * {@see \OCA\OpenRegister\Service\Task\TaskService::get()} and check
 * visibility with
 * {@see \OCA\OpenRegister\Service\Task\TaskAuthorizationService::mayRead()}.
 *
 * 🔴 404, NEVER 403, for a task the caller may not read. This is the same
 * decision {@see TaskController::show()} and {@see TaskController::audit()}
 * already make, and it has to hold here too or the leaf becomes the oracle
 * the task surface refused to be: a 403 from `/notes` would confirm that a
 * guessed uuid names a real task, which is exactly what answering 404 to
 * `/api/flow-tasks/{uuid}` was protecting. One surface answering differently
 * from its neighbour is how an existence check survives being closed.
 *
 * On the WRITE right. Reads and writes are both gated on `mayRead()`,
 * deliberately, and that is a mirror rather than a new policy.
 * `TaskAuthorizationService::RULES` names a right for each LIFECYCLE verb
 * (claim, assign, complete and the rest) and annotating a task is not one
 * of them; adding a rule for it would be writing a policy no spec states,
 * and passing an unnamed verb to `assertMay()` fails closed and would leave
 * the leaf usable by administrators alone. The object leaf's own write
 * right is "you can read the object", so the task leaf's is "you can read
 * the task", and the five relationships `mayRead()` admits (assignee, pool
 * member, requester, watcher, administrator) are precisely the people a
 * task's notes are for. Widening this beyond visibility, or narrowing it to
 * the assignee, is a spec change and belongs in the spec first.
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

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\NoteService;
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
 * REST surface for the notes leaf on a task.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskNotesController extends Controller {

	/**
	 * The body every refusal carries, whether the task is absent or unreadable.
	 *
	 * One constant rather than a literal per verb, because the two cases
	 * MUST stay indistinguishable on the wire: the day one verb answers
	 * 'Task not found' and another answers 'Forbidden', the difference
	 * between them is the existence oracle this controller exists to deny.
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
	 * @param NoteService $notes The same note storage the object leaf uses.
	 * @param IUserSession $userSession Names the acting identity.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TaskService $tasks,
		private readonly TaskAuthorizationService $authorization,
		private readonly NoteService $notes,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List the notes on a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The notes and their count; 404 when the task is
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

		$params = $this->request->getParams();
		$limit = (int)($params['limit'] ?? 50);
		$offset = (int)($params['offset'] ?? 0);

		$notes = $this->notes->getNotesForObject((string)$task->getUuid(), $limit, $offset);

		return new JSONResponse(['results' => $notes, 'total' => count($notes)]);
	}//end index()

	/**
	 * Write a note on a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The created note; 404 when the task is absent OR
	 *                      invisible, 400 when the message is missing.
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

		$message = $this->message();
		if ($message === null) {
			return new JSONResponse(['error' => 'Note message is required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			$this->notes->createNote((string)$task->getUuid(), $message),
			Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Rewrite a note on a task.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $noteId The note to rewrite.
	 *
	 * @return JSONResponse The updated note; 404 when the task is absent OR
	 *                      invisible, 400 when the message is missing.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(string $uuid, string $noteId): JSONResponse {
		if ($this->readableTask(uuid: $uuid) === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		$message = $this->message();
		if ($message === null) {
			return new JSONResponse(['error' => 'Note message is required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->notes->updateNote((int)$noteId, $message));
	}//end update()

	/**
	 * Remove a note from a task.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $noteId The note to remove.
	 *
	 * @return JSONResponse Confirmation; 404 when the task is absent OR
	 *                      invisible.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(string $uuid, string $noteId): JSONResponse {
		if ($this->readableTask(uuid: $uuid) === null) {
			return new JSONResponse(self::NOT_FOUND, Http::STATUS_NOT_FOUND);
		}

		$this->notes->deleteNote((int)$noteId);

		return new JSONResponse(['success' => true]);
	}//end destroy()

	/**
	 * The task this request may act on, or null.
	 *
	 * Fail-closed on every path: absent, invisible and undeterminable all
	 * return null, and every caller turns null into the same 404. A
	 * `Throwable` from the mapper is caught here rather than propagated
	 * because a lookup that could not answer must not be READ as a
	 * permission, and because letting it out would put the mapper's message
	 * (which for a database failure carries SQL and bound parameters) on the
	 * wire, the finding {@see TaskController} already fixed.
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
	 * The note body the request carries, or null when it says nothing.
	 *
	 * A whitespace-only message is nothing: it is stored as a comment that
	 * renders blank, which reads on the task as a note somebody wrote and
	 * the page failed to show.
	 *
	 * @return string|null The message, or null.
	 */
	private function message(): ?string {
		$message = trim((string)($this->request->getParams()['message'] ?? ''));
		if ($message === '') {
			return null;
		}

		return $message;
	}//end message()

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
