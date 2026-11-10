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
 * {@see \OCA\OpenRegister\Files\Service\NoteService} writes Nextcloud comments
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\TaskLeafGuard;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Controller\Support\TaskNotesControllerServices;

/**
 * REST surface for the notes leaf on a task.
 *
 * The absent-or-invisible refusal is {@see TaskLeafGuard}'s, shared with
 * {@see TaskEventsController} so both leaves answer it identically.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
class TaskNotesController extends Controller {

	/**
	 * The shared task guard: resolve, check visibility, or answer 404.
	 *
	 * @var TaskLeafGuard
	 */
	private readonly TaskLeafGuard $guard;

	/**
	 * The same note storage the object leaf uses.
	 *
	 * @var NoteServiceInterface
	 */
	private readonly NoteServiceInterface $notes;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param TaskNotesControllerServices $services The services, bundled.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		TaskNotesControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->notes = $services->notes;
		$this->guard = new TaskLeafGuard(tasks: $services->tasks, authorization: $services->authorization, userSession: $services->userSession);

	}//end __construct()

	/**
	 * List the notes on a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The notes and their count; 404 when the task is
	 *                      absent OR invisible.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $uuid): JSONResponse {
		return $this->guard->withReadableTask(uuid: $uuid, action: $this->listNotes(...));
	}//end index()

	/**
	 * Write a note on a task.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The created note; 404 when the task is absent OR
	 *                      invisible, 400 when the message is missing.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(string $uuid): JSONResponse {
		return $this->guard->withReadableTask(uuid: $uuid, action: $this->writeNote(...));
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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(string $uuid, string $noteId): JSONResponse {
		return $this->guard->withReadableTask(
			uuid: $uuid,
			action: fn (): JSONResponse => $this->rewriteNote(uuid: $uuid, noteId: (int)$noteId)
		);
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
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function destroy(string $uuid, string $noteId): JSONResponse {
		return $this->guard->withReadableTask(
			uuid: $uuid,
			action: fn (): JSONResponse => $this->removeNote(uuid: $uuid, noteId: (int)$noteId)
		);
	}//end destroy()

	/**
	 * The notes page of a readable task.
	 *
	 * @param TaskInterface $task The readable task.
	 *
	 * @return JSONResponse The notes and their count.
	 */
	private function listNotes(TaskInterface $task): JSONResponse {
		$params = $this->request->getParams();
		$limit = (int)($params['limit'] ?? 50);
		$offset = (int)($params['offset'] ?? 0);

		$notes = $this->notes->getNotesForObject((string)$task->getUuid(), $limit, $offset);

		return new JSONResponse(['results' => $notes, 'total' => count($notes)]);
	}//end listNotes()

	/**
	 * Write the request's note on a readable task.
	 *
	 * @param TaskInterface $task The readable task.
	 *
	 * @return JSONResponse The created note, or 400 without a message.
	 */
	private function writeNote(TaskInterface $task): JSONResponse {
		$message = $this->message();
		if ($message === null) {
			return $this->messageRequired();
		}

		return new JSONResponse(
			$this->notes->createNote((string)$task->getUuid(), $message),
			Http::STATUS_CREATED
		);
	}//end writeNote()

	/**
	 * Rewrite a note: the message is checked before the note's ownership.
	 *
	 * @param string $uuid   The task uuid.
	 * @param int    $noteId The note id.
	 *
	 * @return JSONResponse The updated note, 400 without a message, 404 for a foreign note.
	 */
	private function rewriteNote(string $uuid, int $noteId): JSONResponse {
		$message = $this->message();
		if ($message === null) {
			return $this->messageRequired();
		}

		if ($this->isNoteOfTask(uuid: $uuid, noteId: $noteId) === false) {
			return TaskLeafGuard::notFound();
		}

		return new JSONResponse($this->notes->updateNote($noteId, $message));
	}//end rewriteNote()

	/**
	 * Remove a note that hangs on this task.
	 *
	 * @param string $uuid   The task uuid.
	 * @param int    $noteId The note id.
	 *
	 * @return JSONResponse Confirmation, or 404 for a foreign note.
	 */
	private function removeNote(string $uuid, int $noteId): JSONResponse {
		if ($this->isNoteOfTask(uuid: $uuid, noteId: $noteId) === false) {
			return TaskLeafGuard::notFound();
		}

		$this->notes->deleteNote($noteId);

		return new JSONResponse(['success' => true]);
	}//end removeNote()

	/**
	 * The 400 a note without a message gets.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function messageRequired(): JSONResponse {
		return new JSONResponse(['error' => 'Note message is required'], Http::STATUS_BAD_REQUEST);
	}//end messageRequired()

	/**
	 * Whether the note hangs on this task.
	 *
	 * @param string $uuid   The task uuid.
	 * @param int    $noteId The note id.
	 *
	 * @return bool
	 */
	private function isNoteOfTask(string $uuid, int $noteId): bool {
		try {
			$this->notes->requireNoteOf(objectUuid: $uuid, noteId: $noteId);
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}//end isNoteOfTask()

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
}//end class
