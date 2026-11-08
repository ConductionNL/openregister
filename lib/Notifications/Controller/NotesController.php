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
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
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
	 * @param string $appName Application name
	 * @param IRequest $request HTTP request object
	 * @param NotesControllerServices $services The notes controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		NotesControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
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
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->listNotes(object: $object),
			failure: $this->failureWith(status: 500)
		);
	}//end index()

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
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->createOn(object: $object, register: $register, schema: $schema),
			failure: $this->failureWith(status: 400)
		);
	}//end create()

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
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->updateOn(object: $object, noteId: (int)$noteId),
			failure: fn (Exception $e): JSONResponse => $this->updateFailure(e: $e)
		);
	}//end update()

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
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->versionsOf(object: $object, noteId: (int)$noteId),
			failure: $this->failureWith(status: 400)
		);
	}//end versions()

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
		return $this->onObject(
			path: [$register, $schema, $id],
			action: fn (ObjectEntity $object): JSONResponse => $this->destroyOn(object: $object, noteId: (int)$noteId),
			failure: fn (Exception $e): JSONResponse => $this->failureWith(status: $this->statusFor(e: $e))($e)
		);
	}//end destroy()

	/**
	 * Run an action on the object the path names, translating the shared outcomes.
	 *
	 * An object that cannot be resolved, or that does not exist, answers 404
	 * before the action runs; any other failure is handed to `$failure`. A
	 * `DoesNotExistException` raised by the action itself answers the same 404.
	 *
	 * @param array{0: string, 1: string, 2: string} $path    The register, schema and object id.
	 * @param \Closure(ObjectEntity): JSONResponse     $action  What to do with the object.
	 * @param \Closure(Exception): JSONResponse        $failure How to answer any other failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function onObject(array $path, \Closure $action, \Closure $failure): JSONResponse {
		try {
			$object = $this->validateObject(register: $path[0], schema: $path[1], id: $path[2]);
			if ($object === null) {
				return $this->notFound(what: 'Object');
			}

			return $action($object);
		} catch (DoesNotExistException $e) {
			return $this->notFound(what: 'Object');
		} catch (Exception $e) {
			return $failure($e);
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
	 * @param ObjectEntity $object   The object.
	 * @param string       $register The register from the path.
	 * @param string       $schema   The schema from the path.
	 *
	 * @return JSONResponse The created note, or the refusal.
	 */
	private function createOn(ObjectEntity $object, string $register, string $schema): JSONResponse {
		$data = $this->request->getParams();
		if (empty($data['message']) === true) {
			return new JSONResponse(data: ['error' => 'Note message is required'], statusCode: 400);
		}

		$refusal = $this->refuseRequestedVisibility(object: $object, data: $data);
		if ($refusal !== null) {
			return $refusal;
		}

		$note = $this->noteService->createNote(objectUuid: $object->getUuid(), message: $data['message'], visibility: $this->visibilityOf(data: $data));

		return $this->projected(object: $object, note: $note, register: $register, schema: $schema);
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
	 * @param ObjectEntity         $object   The object.
	 * @param array<string, mixed> $note     The created note.
	 * @param string               $register The register from the path.
	 * @param string               $schema   The schema from the path.
	 *
	 * @return JSONResponse The note, with its `entryId` when it got a record.
	 */
	private function projected(ObjectEntity $object, array $note, string $register, string $schema): JSONResponse {
		$entry = $this->services->timeline->projectNote(
			object: $object,
			note: new NoteProjection(note: $note, register: $register, schema: $schema)
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

		$write = $this->resolveNoteWrite(object: $object, data: $this->request->getParams(), noteId: $noteId);
		if ($write['refusal'] !== null) {
			return $write['refusal'];
		}

		$mayManage = $this->services->visibility->mayManageObject(object: $object);
		$note = $this->noteService->updateNote(noteId: $noteId, message: $write['message'], visibility: $write['visibility'], mayManage: $mayManage);
		$this->recordUpdate(object: $object, noteId: $noteId, write: $write, note: $note);

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
	 * @param ObjectEntity                                                                    $object The object.
	 * @param int                                                                             $noteId The note.
	 * @param array{refusal: null, message: string|null, visibility: string|null, previous: string|null} $write  The write made.
	 * @param array<string, mixed>                                                            $note   The updated note.
	 *
	 * @return void
	 */
	private function recordUpdate(ObjectEntity $object, int $noteId, array $write, array $note): void {
		if ($write['message'] !== null) {
			$this->noteService->auditEdit(object: $object, noteId: $noteId, versions: (int)($note['versionCount'] ?? 0));
		}

		$this->services->timeline->rewrite(
			object: $object,
			rewrite: new NoteRewrite(commentId: $noteId, message: $write['message'], visibility: $write['visibility'])
		);

		if ($write['previous'] !== null) {
			$to = (string)($note['visibility'] ?? '');
			$this->services->visibility->auditVisibilityChange(object: $object, noteId: $noteId, from: $write['previous'], to: $to);
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
	 * @param ObjectEntity $object The object the note hangs on
	 * @param array<string,mixed> $data The request payload
	 * @param int $noteId The note being updated
	 *
	 * @return array{refusal: JSONResponse|null, message: string|null, visibility: string|null, previous: string|null}
	 *         The refusal to return, or the write to make.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	private function resolveNoteWrite(ObjectEntity $object, array $data, int $noteId): array {
		$message = $this->messageOf(data: $data);
		$write = ['refusal' => null, 'message' => $message, 'visibility' => null, 'previous' => null];
		if (array_key_exists('visibility', $data) === true) {
			return $this->withVisibility(write: $write, object: $object, requested: $data['visibility'], noteId: $noteId);
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
	 * Add a visibility move to a write, or the refusal of it.
	 *
	 * @param array{refusal: JSONResponse|null, message: string|null, visibility: string|null, previous: string|null} $write     The write so far.
	 * @param ObjectEntity                                                                                     $object    The object the note hangs on.
	 * @param mixed                                                                                            $requested The raw `visibility` value.
	 * @param int                                                                                              $noteId    The note being updated.
	 *
	 * @return array{refusal: JSONResponse|null, message: string|null, visibility: string|null, previous: string|null}
	 *         The write, with its visibility and the previous one, or with a refusal.
	 */
	private function withVisibility(array $write, ObjectEntity $object, mixed $requested, int $noteId): array {
		$refusal = $this->refuseVisibility(object: $object, requested: $requested);
		if ($refusal !== null) {
			$write['refusal'] = $refusal;
			return $write;
		}

		$write['visibility'] = (string)$requested;
		$write['previous'] = (string)($this->noteService->getNote(noteId: $noteId)['visibility'] ?? '');

		return $write;
	}//end withVisibility()

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
	 * @param string $register The register slug or identifier
	 * @param string $schema The schema slug or identifier
	 * @param string $id The object ID
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
	private function validateObject(
		string $register,
		string $schema,
		string $id,
	): ?ObjectEntity {
		// REGISTER FIRST. `setSchema()` scopes its slug lookup to whatever
		// register is currently set, and ObjectService is reused across many
		// operations in one process — so setting the schema first resolves it
		// against a register LEFT BEHIND by an unrelated call. Measured here:
		// `/objects/dossiq/case/{id}/notes` threw `Schema slug "case" is not
		// carried by register "buildiq"`, an app this request never mentioned.
		// Naming the register first makes the boundary the caller's own.
		$this->objectService->setRegister($register);
		$this->objectService->setSchema($schema);
		$this->objectService->setObject($id);

		return $this->objectService->getObject();
	}//end validateObject()
}//end class
