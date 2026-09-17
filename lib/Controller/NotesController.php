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

namespace OCA\OpenRegister\Controller;

use Exception;
use OCA\OpenRegister\Exception\NoteWriteRefusedException;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Timeline\TimelineEntryService;
use OCA\OpenRegister\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Service\TimelineVisibilityService;
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
	 * @var NoteService
	 */
	private readonly NoteService $noteService;

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
	 * @param NoteService $noteService Note service for comment operations
	 * @param ObjectService $objectService Object service for object validation
	 * @param TimelineVisibilityService $visibility Visibility guard, filter and audit
	 * @param TimelineWriteService $timeline Projects a note into the entry record, so it is searchable
	 * @param TimelineEntryService $entries Reads and forgets the record behind a note
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		NoteService $noteService,
		ObjectService $objectService,
		private readonly TimelineVisibilityService $visibility,
		private readonly TimelineWriteService $timeline,
		private readonly TimelineEntryService $entries,
	) {
		parent::__construct(appName: $appName, request: $request);

		$this->noteService = $noteService;
		$this->objectService = $objectService;
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			$params = $this->request->getParams();
			$limit = (int)($params['limit'] ?? 50);
			$offset = (int)($params['offset'] ?? 0);

			$requested = null;
			if (isset($params['visibility']) === true && is_string($params['visibility']) === true) {
				$requested = $params['visibility'];
			}

			$mayManage = $this->visibility->mayManage(object: $object);
			$filter = $this->visibility->effectiveFilter(object: $object, requested: $requested);

			$notes = $this->noteService->getNotesForObject(
				objectUuid: $object->getUuid(),
				limit: $limit,
				offset: $offset,
				visibility: $filter
			);

			return new JSONResponse(
				data: [
					'results' => $notes,
					'total' => count($notes),
					'visibility' => $filter,
					'canSetVisibility' => $mayManage,
				]
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}//end try
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			$data = $this->request->getParams();

			// Validate required fields.
			if (empty($data['message']) === true) {
				return new JSONResponse(
					data: ['error' => 'Note message is required'],
					statusCode: 400
				);
			}

			$visibility = null;
			if (array_key_exists('visibility', $data) === true) {
				$refusal = $this->refuseVisibility(object: $object, requested: $data['visibility']);
				if ($refusal !== null) {
					return $refusal;
				}

				$visibility = (string)$data['visibility'];
			}

			$note = $this->noteService->createNote(
				objectUuid: $object->getUuid(),
				message: $data['message'],
				visibility: $visibility
			);

			// The one line this endpoint gains. A note written here still
			// behaves exactly as it did — same payload, same shape, no kind
			// and no fields — but it now also has a record, so the entry
			// search can find it. Half a timeline that cannot be searched is
			// worse than none. The projection never throws.
			$entry = $this->timeline->projectNote(
				object: $object,
				note: $note,
				register: $register,
				schema: $schema
			);
			if ($entry !== null) {
				$note['entryId'] = $entry->getUuid();
			}

			return new JSONResponse(data: $note, statusCode: 201);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
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
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function update(
		string $register,
		string $schema,
		string $id,
		string $noteId,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			$write = $this->resolveNoteWrite(
				object: $object,
				data: $this->request->getParams(),
				noteId: (int)$noteId
			);
			if ($write['refusal'] !== null) {
				return $write['refusal'];
			}

			$note = $this->noteService->updateNote(
				noteId: (int)$noteId,
				message: $write['message'],
				visibility: $write['visibility'],
				mayManage: $this->visibility->mayManageObject(object: $object)
			);

			if ($write['message'] !== null) {
				// The trail records that the note changed and who changed it;
				// the text it used to carry stays in the versions, which is
				// what keeps the trail small and readable.
				$this->noteService->auditEdit(
					object: $object,
					noteId: (int)$noteId,
					versions: (int)($note['versionCount'] ?? 0)
				);
			}

			// Keep the record and its references in step with the text that
			// was just rewritten: an index answering with yesterday's sentence
			// is worse than no index, because it looks like a hit.
			$this->timeline->rewrite(
				object: $object,
				commentId: (int)$noteId,
				message: $write['message'],
				visibility: $write['visibility']
			);

			if ($write['previous'] !== null) {
				$this->visibility->auditVisibilityChange(
					object: $object,
					noteId: (int)$noteId,
					from: $write['previous'],
					to: (string)($note['visibility'] ?? '')
				);
			}

			return new JSONResponse(data: $note);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (NoteWriteRefusedException $e) {
			// Caught ahead of the generic Exception below, which it extends:
			// a locked note answers 423 and a note the caller may not rewrite
			// answers 403, each legible as what it is rather than as a bad
			// request. The refusal carries its own status.
			return new JSONResponse(
				data: ['error' => $e->getMessage()],
				statusCode: $e->getHttpStatus()
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
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
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
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
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function versions(
		string $register,
		string $schema,
		string $id,
		string $noteId,
	): JSONResponse {
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			try {
				$note = $this->noteService->getNote(noteId: (int)$noteId);
			} catch (Exception $e) {
				// A note that is not there is a 404, not the 400 a generic
				// failure would give: the caller asked for something absent,
				// it did not ask wrongly.
				return new JSONResponse(data: ['error' => 'Note not found'], statusCode: 404);
			}

			// The same filter the note list applies, asked of one note: a
			// reader who may not see an internal note may not read the texts
			// it replaced either.
			$filter = $this->visibility->effectiveFilter(object: $object, requested: null);
			if (count($this->visibility->filterRows(rows: [$note], filter: $filter)) === 0) {
				return new JSONResponse(data: ['error' => 'Note not found'], statusCode: 404);
			}

			$versions = $this->noteService->noteVersions(noteId: (int)$noteId);

			return new JSONResponse(
				data: [
					'results' => $versions,
					'total' => count($versions),
				]
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}//end try
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
		try {
			$object = $this->validateObject(register: $register, schema: $schema, id: $id);
			if ($object === null) {
				return new JSONResponse(
					data: ['error' => 'Object not found'],
					statusCode: 404
				);
			}

			// Read the record BEFORE the note goes, so its references can be
			// forgotten by id: after the delete there is nothing left to look
			// the entry up by.
			$entry = $this->entries->entryForNote(commentId: (int)$noteId);

			$this->noteService->deleteNote((int)$noteId);

			$this->timeline->forgetNote(
				commentId: (int)$noteId,
				entryUuid: $entry?->getUuid()
			);

			return new JSONResponse(data: ['success' => true]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Object not found'], statusCode: 404);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 400);
		}
	}//end destroy()

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
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The object the note hangs on
	 * @param array<string,mixed> $data The request payload
	 * @param int $noteId The note being updated
	 *
	 * @return array{refusal: JSONResponse|null, message: string|null, visibility: string|null, previous: string|null}
	 *         The refusal to return, or the write to make.
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	private function resolveNoteWrite(
		\OCA\OpenRegister\Db\ObjectEntity $object,
		array $data,
		int $noteId,
	): array {
		$write = [
			'refusal' => null,
			'message' => null,
			'visibility' => null,
			'previous' => null,
		];

		$wantsVisibility = array_key_exists('visibility', $data);
		$hasMessage = (empty($data['message']) === false);

		if ($hasMessage === false && $wantsVisibility === false) {
			$write['refusal'] = new JSONResponse(
				data: ['error' => 'Note message is required'],
				statusCode: 400
			);

			return $write;
		}

		if ($hasMessage === true) {
			$write['message'] = (string)$data['message'];
		}

		if ($wantsVisibility === false) {
			return $write;
		}

		$refusal = $this->refuseVisibility(object: $object, requested: $data['visibility']);
		if ($refusal !== null) {
			$write['refusal'] = $refusal;

			return $write;
		}

		$write['visibility'] = (string)$data['visibility'];
		$write['previous'] = (string)($this->noteService->getNote(noteId: $noteId)['visibility'] ?? '');

		return $write;
	}//end resolveNoteWrite()

	/**
	 * Refuse a visibility write the caller may not make, or one it spelled wrong.
	 *
	 * Returns null when the write may proceed, so a call site reads as one
	 * guard clause. The permission asked for is `update` on the object: moving
	 * a note across the counter is a write on the object's audience, not on
	 * the note's text, so the author's own right is not enough.
	 *
	 * @param \OCA\OpenRegister\Db\ObjectEntity $object The object the note hangs on
	 * @param mixed $requested The raw `visibility` value from the payload
	 *
	 * @return JSONResponse|null A 400 or 403 response, or null when the write is allowed
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	private function refuseVisibility(\OCA\OpenRegister\Db\ObjectEntity $object, mixed $requested): ?JSONResponse {
		$value = null;
		if (is_string($requested) === true) {
			$value = $requested;
		}

		if ($this->visibility->isKnownValue(value: $value) === false) {
			return new JSONResponse(
				data: ['error' => 'Visibility must be either internal or public'],
				statusCode: 400
			);
		}

		if ($this->visibility->mayManage(object: $object) === false) {
			return new JSONResponse(
				data: ['error' => 'You do not have permission to set the visibility of this note'],
				statusCode: 403
			);
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
	 * @return \OCA\OpenRegister\Db\ObjectEntity|null The object or null
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
	): ?\OCA\OpenRegister\Db\ObjectEntity {
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
