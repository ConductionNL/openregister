<?php

/**
 * NotesController
 *
 * REST controller for note operations on OpenRegister objects.
 * Follows the FilesController pattern for sub-resource endpoints.
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
 *
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use Exception;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Notifications\Controller\Support\AddressedObject;
use OCA\OpenRegister\Notifications\Controller\Support\NotePath;
use OCA\OpenRegister\Notifications\Controller\Support\NoteStep;
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
use OCA\OpenRegister\Notifications\Controller\Support\NoteUpdate;
use OCA\OpenRegister\Notifications\Controller\Support\ObjectNote;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NoteWriteRefusedException;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteProjection;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteRewrite;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Notifications\Service\NoteVisibilityMove;

/**
 * NotesController handles note operations for objects in registers.
 *
 * Provides REST API endpoints for managing notes (comments)
 * associated with OpenRegister objects.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */
class NotesController extends Controller {

	/**
	 * Note service for comment operations.
	 *
	 * @var NoteServiceInterface
	 */
	private readonly NoteServiceInterface $noteService;

	/**
	 * Object service for object validation.
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objectService;

	/**
	 * The controller's injected services.
	 *
	 * @var NotesControllerServices
	 */
	private readonly NotesControllerServices $services;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request object
	 * @param NotesControllerServices $services The notes controller services.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		NotesControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->services = $services;
		$this->noteService = $services->noteService;
		$this->objectService = $services->objectService;
	}//end __construct()

	/**
	 * List all notes for a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse JSON response with notes list
	 *
	 * A caller without `update` on the object is served the public view even
	 * when it asks for none: the flag is what makes a single timeline safe to
	 * show a citizen, and a filter a caller can drop is no filter at all.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function index(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		return $this->notesAt(path: new NotePath(register: $register, schema: $schema, id: $id));
	}//end index()

	/**
	 * The notes on the object at a route path.
	 *
	 * @param NotePath $path The register, schema and object id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function notesAt(NotePath $path): JSONResponse {
		return $this->onObject(
			path: $path,
			step: new NoteStep(
				action: fn (ObjectEntity $object): JSONResponse => $this->listNotes(object: $object),
				failure: $this->failureWith(status: 500)
			)
		);
	}//end notesAt()

	/**
	 * Create a new note on a specific object.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 *
	 * @return JSONResponse JSON response with the created note
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function create(
		string $register,
		string $schema,
		string $id,
	): JSONResponse {
		return $this->createAt(path: new NotePath(register: $register, schema: $schema, id: $id));
	}//end create()

	/**
	 * Add a note to the object at a route path.
	 *
	 * @param NotePath $path The register, schema and object id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function createAt(NotePath $path): JSONResponse {
		return $this->onObject(
			path: $path,
			step: new NoteStep(
				action: fn (ObjectEntity $object): JSONResponse => $this->createOn(target: new AddressedObject(object: $object, path: $path)),
				failure: $this->failureWith(status: 400)
			)
		);
	}//end createAt()

	/**
	 * Update a note.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $noteId The ID of the note to update
	 *
	 * @return JSONResponse JSON response with the updated note
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function update(
		string $register,
		string $schema,
		string $id,
		string $noteId,
	): JSONResponse {
		return $this->updateAt(path: new NotePath(register: $register, schema: $schema, id: $id), noteId: $noteId);
	}//end update()

	/**
	 * Update one note on the object at a route path.
	 *
	 * @param NotePath $path   The register, schema and object id from the route.
	 * @param string   $noteId The note id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function updateAt(NotePath $path, string $noteId): JSONResponse {
		return $this->onObject(
			path: $path,
			step: new NoteStep(
				action: fn (ObjectEntity $object): JSONResponse => $this->updateOn(object: $object, noteId: (int)$noteId),
				failure: fn (Exception $e): JSONResponse => $this->updateFailure(e: $e)
			)
		);
	}//end updateAt()

	/**
	 * Update a note, reached over PATCH.
	 *
	 * The canonical verb for changing part of a note: a caller sends only the
	 * message, or only the visibility, and leaves the rest alone. The PUT
	 * route stays where it is so no existing client breaks, and both land on
	 * the same handler so the two verbs can never drift apart.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $noteId The ID of the note to update
	 *
	 * @return JSONResponse JSON response with the updated note
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function patch(
		string $register,
		string $schema,
		string $id,
		string $noteId,
	): JSONResponse {
		return $this->update(register: $register, schema: $schema, id: $id, noteId: $noteId);
	}//end patch()

	/**
	 * List what a note used to say.
	 *
	 * Reading the history is reading the note: the list is served to anyone
	 * the note list itself would serve, which for a caller without `update` on
	 * the object means the note has to be a public one.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $noteId The ID of the note whose history is read
	 *
	 * @return JSONResponse JSON response with the versions, newest first
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function versions(
		string $register,
		string $schema,
		string $id,
		string $noteId,
	): JSONResponse {
		return $this->versionsAt(path: new NotePath(register: $register, schema: $schema, id: $id), noteId: $noteId);
	}//end versions()

	/**
	 * The versions of one note on the object at a route path.
	 *
	 * @param NotePath $path   The register, schema and object id from the route.
	 * @param string   $noteId The note id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function versionsAt(NotePath $path, string $noteId): JSONResponse {
		return $this->onObject(
			path: $path,
			step: new NoteStep(
				action: fn (ObjectEntity $object): JSONResponse => $this->versionsOf(object: $object, noteId: (int)$noteId),
				failure: $this->failureWith(status: 400)
			)
		);
	}//end versionsAt()

	/**
	 * Delete a note.
	 *
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The ID of the object
	 * @param string $noteId The ID of the note to delete
	 *
	 * @return JSONResponse JSON response confirming deletion
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function destroy(
		string $register,
		string $schema,
		string $id,
		string $noteId,
	): JSONResponse {
		return $this->destroyAt(path: new NotePath(register: $register, schema: $schema, id: $id), noteId: $noteId);
	}//end destroy()

	/**
	 * Delete one note on the object at a route path.
	 *
	 * @param NotePath $path   The register, schema and object id from the route.
	 * @param string   $noteId The note id from the route.
	 *
	 * @return JSONResponse The answer, or the refusal for an object that does not resolve.
	 */
	private function destroyAt(NotePath $path, string $noteId): JSONResponse {
		return $this->onObject(
			path: $path,
			step: new NoteStep(
				action: fn (ObjectEntity $object): JSONResponse => $this->destroyOn(object: $object, noteId: (int)$noteId),
				failure: fn (Exception $e): JSONResponse => $this->failureWith(status: $this->statusFor(e: $e))($e)
			)
		);
	}//end destroyAt()

	/**
	 * Run an action on the object the path names, translating the shared outcomes.
	 *
	 * An object that cannot be resolved, or that does not exist, answers 404
	 * before the action runs; any other failure is handed to `$failure`. A
	 * `DoesNotExistException` raised by the action itself answers the same 404.
	 *
	 * @param NotePath $path The register, schema and object id.
	 * @param NoteStep $step What to do with the object, and how to answer any other failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function onObject(NotePath $path, NoteStep $step): JSONResponse {
		try {
			$object = $this->validateObject(path: $path);
			if ($object === null) {
				return $this->notFound(what: 'Object');
			}

			return ($step->action)($object);
		} catch (DoesNotExistException $e) {
			return $this->notFound(what: 'Object');
		} catch (Exception $e) {
			return ($step->failure)($e);
		}
	}//end onObject()

	/**
	 * A 404 naming what was not found.
	 *
	 * @param string $what "Object" or "Note".
	 *
	 * @return JSONResponse The 404.
	 */
	private function notFound(string $what): JSONResponse {
		return new JSONResponse(data: ['error' => $what . ' not found'], statusCode: 404);
	}//end notFound()

	/**
	 * Answers a failure with its message under the given status.
	 *
	 * @param int $status The status.
	 *
	 * @return \Closure(Exception): JSONResponse The answer.
	 */
	private function failureWith(int $status): \Closure {
		return static fn (Exception $e): JSONResponse => new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $status);
	}//end failureWith()

	/**
	 * 404 for a failure that says it is one, 400 for any other.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return int The status.
	 */
	private function statusFor(Exception $e): int {
		if ($e->getCode() === 404) {
			return 404;
		}

		return 400;
	}//end statusFor()

	/**
	 * How an update failure is answered.
	 *
	 * A refused write carries its own status: a locked note answers 423 and a
	 * note the caller may not rewrite answers 403, each legible as what it is
	 * rather than as a bad request. Any other failure is a 404 when it says so,
	 * else a 400.
	 *
	 * @param Exception $e The failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function updateFailure(Exception $e): JSONResponse {
		if ($e instanceof NoteWriteRefusedException) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $e->getHttpStatus());
		}

		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: $this->statusFor(e: $e));
	}//end updateFailure()

	/**
	 * List the object's notes the caller may read.
	 *
	 * A caller without `update` on the object is served the public view even
	 * when it asks for none: the flag is what makes a single timeline safe to
	 * show a citizen, and a filter a caller can drop is no filter at all.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return JSONResponse The notes.
	 */
	private function listNotes(ObjectEntity $object): JSONResponse {
		$params = $this->request->getParams();
		$mayManage = $this->services->visibility->mayManage(object: $object);
		$filter = $this->services->visibility->effectiveFilter(object: $object, requested: $this->requestedVisibility(params: $params));

		$notes = $this->noteService->getNotesForObject(
			objectUuid: $object->getUuid(),
			limit: (int)($params['limit'] ?? 50),
			offset: (int)($params['offset'] ?? 0),
			visibility: $filter
		);

		return new JSONResponse(data: ['results' => $notes, 'total' => count($notes), 'visibility' => $filter, 'canSetVisibility' => $mayManage]);
	}//end listNotes()

	/**
	 * The visibility a list request asks for, or null when it names none.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return string|null The visibility, or null.
	 */
	private function requestedVisibility(array $params): ?string {
		if (isset($params['visibility']) === true && is_string($params['visibility']) === true) {
			return $params['visibility'];
		}

		return null;
	}//end requestedVisibility()

	/**
	 * Create a note on the object, refusing a visibility the caller may not set.
	 *
	 * @param AddressedObject $target The object, with the path it was addressed by.
	 *
	 * @return JSONResponse The created note, or the refusal.
	 */
	private function createOn(AddressedObject $target): JSONResponse {
		$object = $target->object;
		$data = $this->request->getParams();
		if (empty($data['message']) === true) {
			return new JSONResponse(data: ['error' => 'Note message is required'], statusCode: 400);
		}

		$refusal = $this->refuseRequestedVisibility(object: $object, data: $data);
		if ($refusal !== null) {
			return $refusal;
		}

		$note = $this->noteService->createNote(objectUuid: $object->getUuid(), message: $data['message'], visibility: $this->visibilityOf(data: $data));

		return $this->projected(target: $target, note: $note);
	}//end createOn()

	/**
	 * Refuse the payload's visibility when it carries one the caller may not set.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $data   The payload.
	 *
	 * @return JSONResponse|null The refusal, or null when there is none to make.
	 */
	private function refuseRequestedVisibility(ObjectEntity $object, array $data): ?JSONResponse {
		if (array_key_exists('visibility', $data) === false) {
			return null;
		}

		return $this->refuseVisibility(object: $object, requested: $data['visibility']);
	}//end refuseRequestedVisibility()

	/**
	 * The payload's visibility as text, or null when it carries none.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return string|null The visibility, or null.
	 */
	private function visibilityOf(array $data): ?string {
		if (array_key_exists('visibility', $data) === false) {
			return null;
		}

		return (string)$data['visibility'];
	}//end visibilityOf()

	/**
	 * Answer a created note, after giving it a timeline record.
	 *
	 * The one line this endpoint gains. A note written here still behaves
	 * exactly as it did — same payload, same shape, no kind and no fields —
	 * but it now also has a record, so the entry search can find it. Half a
	 * timeline that cannot be searched is worse than none. The projection
	 * never throws.
	 *
	 * @param AddressedObject      $target The object, with the path it was addressed by.
	 * @param array<string, mixed> $note   The created note.
	 *
	 * @return JSONResponse The note, with its `entryId` when it got a record.
	 */
	private function projected(AddressedObject $target, array $note): JSONResponse {
		$entry = $this->services->timeline->projectNote(
			object: $target->object,
			note: new NoteProjection(note: $note, register: $target->path->register, schema: $target->path->schema)
		);
		if ($entry !== null) {
			$note['entryId'] = $entry->getUuid();
		}

		return new JSONResponse(data: $note, statusCode: 201);
	}//end projected()

	/**
	 * Update one of the object's notes.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $noteId The note.
	 *
	 * @return JSONResponse The updated note, or the refusal.
	 */
	private function updateOn(ObjectEntity $object, int $noteId): JSONResponse {
		$this->noteService->requireNoteOf(objectUuid: (string)$object->getUuid(), noteId: $noteId);

		$target = new ObjectNote(object: $object, noteId: $noteId);
		$write = $this->resolveNoteWrite(target: $target, data: $this->request->getParams());
		if ($write['refusal'] !== null) {
			return $write['refusal'];
		}

		$update    = new NoteUpdate(target: $target, message: $write['message'], visibility: $write['visibility'], previous: $write['previous']);
		$mayManage = $this->services->visibility->mayManageObject(object: $object);
		$note = $this->noteService->updateNote(noteId: $noteId, message: $update->message, visibility: $update->visibility, mayManage: $mayManage);
		$this->recordUpdate(update: $update, note: $note);

		return new JSONResponse(data: $note);
	}//end updateOn()

	/**
	 * Record what an update changed: the audit trail, the timeline record, the visibility move.
	 *
	 * The trail records that the note changed and who changed it; the text it
	 * used to carry stays in the versions, which is what keeps the trail small
	 * and readable. The record and its references are kept in step with the
	 * text that was just rewritten: an index answering with yesterday's
	 * sentence is worse than no index, because it looks like a hit.
	 *
	 * @param NoteUpdate           $update The edit made.
	 * @param array<string, mixed> $note   The updated note.
	 *
	 * @return void
	 */
	private function recordUpdate(NoteUpdate $update, array $note): void {
		$object = $update->target->object;
		$noteId = $update->target->noteId;
		if ($update->message !== null) {
			$this->noteService->auditEdit(object: $object, noteId: $noteId, versions: (int)($note['versionCount'] ?? 0));
		}

		$this->services->timeline->rewrite(
			object: $object,
			rewrite: new NoteRewrite(commentId: $noteId, message: $update->message, visibility: $update->visibility)
		);

		if ($update->previous !== null) {
			$to = (string)($note['visibility'] ?? '');
			$move = new NoteVisibilityMove(noteId: $noteId, from: $update->previous, to: $to);
			$this->services->visibility->auditNoteMove(object: $object, move: $move);
		}
	}//end recordUpdate()

	/**
	 * List what one of the object's notes used to say.
	 *
	 * Reading the history is reading the note: a note that is not there is a
	 * 404, and a reader who may not see an internal note may not read the
	 * texts it replaced either — the same filter the note list applies.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $noteId The note.
	 *
	 * @return JSONResponse The versions, newest first, or a 404.
	 */
	private function versionsOf(ObjectEntity $object, int $noteId): JSONResponse {
		try {
			$this->noteService->requireNoteOf(objectUuid: (string)$object->getUuid(), noteId: $noteId);
			$note = $this->noteService->getNote(noteId: $noteId);
		} catch (Exception $e) {
			return $this->notFound(what: 'Note');
		}

		$filter = $this->services->visibility->effectiveFilter(object: $object, requested: null);
		if (count($this->services->visibility->filterRows(rows: [$note], filter: $filter)) === 0) {
			return $this->notFound(what: 'Note');
		}

		$versions = $this->noteService->noteVersions(noteId: $noteId);

		return new JSONResponse(data: ['results' => $versions, 'total' => count($versions)]);
	}//end versionsOf()

	/**
	 * Delete one of the object's notes and forget its timeline record.
	 *
	 * The record is read BEFORE the note goes, so its references can be
	 * forgotten by id: after the delete there is nothing left to look the
	 * entry up by.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int          $noteId The note.
	 *
	 * @return JSONResponse The confirmation.
	 */
	private function destroyOn(ObjectEntity $object, int $noteId): JSONResponse {
		$this->noteService->requireNoteOf(objectUuid: (string)$object->getUuid(), noteId: $noteId);
		$entry = $this->services->timeline->entryForNote(commentId: $noteId);

		$this->noteService->deleteNote($noteId);
		$this->services->timeline->forgetNote(commentId: $noteId, entryUuid: $entry?->getUuid());

		return new JSONResponse(data: ['success' => true]);
	}//end destroyOn()

	/**
	 * Work out what an update writes, or why it may not be written.
	 *
	 * Pulled out of {@see update()} so that method stays under the complexity
	 * the standard allows. The shape it returns is the whole decision: a
	 * `refusal` response to hand straight back, or the message and visibility
	 * to write, plus the `previous` visibility the audit entry needs.
	 * `previous` is null whenever the caller did not ask to move the flag, so
	 * the caller audits nothing.
	 *
	 * @param ObjectNote          $target The note being updated and the object it hangs on
	 * @param array<string,mixed> $data   The request payload
	 *
	 * @return array{refusal: JSONResponse|null, message: string|null, visibility: string|null, previous: string|null}
	 *         The refusal to return, or the write to make.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	private function resolveNoteWrite(ObjectNote $target, array $data): array {
		$message = $this->messageOf(data: $data);
		$write = ['refusal' => null, 'message' => $message, 'visibility' => null, 'previous' => null];
		if (array_key_exists('visibility', $data) === true) {
			return array_merge($write, $this->visibilityMove(target: $target, requested: $data['visibility']));
		}

		if ($message === null) {
			$write['refusal'] = new JSONResponse(data: ['error' => 'Note message is required'], statusCode: 400);
		}

		return $write;
	}//end resolveNoteWrite()

	/**
	 * The payload's message, or null when it carries none (an empty one counts as none).
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return string|null The message, or null.
	 */
	private function messageOf(array $data): ?string {
		if (empty($data['message']) === true) {
			return null;
		}

		return (string)$data['message'];
	}//end messageOf()

	/**
	 * The visibility move a write adds, or the refusal of it.
	 *
	 * @param ObjectNote $target    The note being updated and the object it hangs on.
	 * @param mixed      $requested The raw `visibility` value.
	 *
	 * @return array{refusal: JSONResponse}|array{visibility: string, previous: string}
	 *         The refusal, or the visibility to write and the previous one.
	 */
	private function visibilityMove(ObjectNote $target, mixed $requested): array {
		$refusal = $this->refuseVisibility(object: $target->object, requested: $requested);
		if ($refusal !== null) {
			return ['refusal' => $refusal];
		}

		return [
			'visibility' => (string)$requested,
			'previous'   => (string)($this->noteService->getNote(noteId: $target->noteId)['visibility'] ?? ''),
		];
	}//end visibilityMove()

	/**
	 * Refuse a visibility write the caller may not make, or one it spelled wrong.
	 *
	 * Returns null when the write may proceed, so a call site reads as one
	 * guard clause. The permission asked for is `update` on the object: moving
	 * a note across the counter is a write on the object's audience, not on
	 * the note's text, so the author's own right is not enough.
	 *
	 * @param ObjectEntity $object The object the note hangs on
	 * @param mixed $requested The raw `visibility` value from the payload
	 *
	 * @return JSONResponse|null A 400 or 403 response, or null when the write is allowed
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	private function refuseVisibility(ObjectEntity $object, mixed $requested): ?JSONResponse {
		$value = null;
		if (is_string($requested) === true) {
			$value = $requested;
		}

		if ($this->services->visibility->isKnownValue(value: $value) === false) {
			return new JSONResponse(data: ['error' => 'Visibility must be either internal or public'], statusCode: 400);
		}

		if ($this->services->visibility->mayManage(object: $object) === false) {
			$error = 'You do not have permission to set the visibility of this note';
			return new JSONResponse(data: ['error' => $error], statusCode: 403);
		}

		return null;
	}//end refuseVisibility()

	/**
	 * Validate that the object exists and return it.
	 *
	 * @param NotePath $path The register, schema and object id
	 *
	 * @return ObjectEntity|null The object or null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-4
	 *
	 * @throws DoesNotExistException When no such object exists. Deliberately propagated rather
	 *                               than caught: every call site already wraps this helper and translates it to a 404.
	 *                               Swallowing it here would collapse "no such object" into the same null this method
	 *                               returns for other reasons, which the caller could no longer tell apart.
	 */
	private function validateObject(NotePath $path): ?ObjectEntity {
		// REGISTER FIRST. `setSchema()` scopes its slug lookup to whatever
		// register is currently set, and ObjectService is reused across many
		// operations in one process — so setting the schema first resolves it
		// against a register LEFT BEHIND by an unrelated call. Measured here:
		// `/objects/dossiq/case/{id}/notes` threw `Schema slug "case" is not
		// carried by register "buildiq"`, an app this request never mentioned.
		// Naming the register first makes the boundary the caller's own.
		$this->objectService->setRegister($path->register);
		$this->objectService->setSchema($path->schema);
		$this->objectService->setObject($path->id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
