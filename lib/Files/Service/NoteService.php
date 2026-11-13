<?php

/**
 * NoteService
 *
 * Service that wraps Nextcloud's ICommentsManager for adding notes to OpenRegister objects.
 * Notes are stored as standard Nextcloud comments with objectType "openregister".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use Exception;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteAuthor;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteEdit;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectNotesQuery;
use OCA\OpenRegister\Db\StoredEntity;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Exception\NoteLockedException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;

/**
 * NoteService wraps ICommentsManager for OpenRegister object notes.
 *
 * Provides methods to create, list, and delete notes (comments)
 * linked to OpenRegister objects using the objectType "openregister".
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class NoteService implements NoteServiceInterface {

	/**
	 * The objectType used for OpenRegister comments.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE = 'openregister';

	/**
	 * The key the visibility flag is stored under in the comment's metadata.
	 *
	 * A note is a Nextcloud comment, and a comment carries its own metadata
	 * array, so the flag needs no second table and no join. Other keys in that
	 * array are preserved on every write.
	 *
	 * @var string
	 */
	private const VISIBILITY_KEY = 'visibility';

	/**
	 * The comment verb a locked note carries.
	 *
	 * The lock is a verb rather than a second table because `ICommentsManager`
	 * already stores one per comment, which is the storage
	 * `notes-leaf-rich-text-lock-export` (design D-2) settled on. This service
	 * only READS it: what sets the verb is that change, and an edit is refused
	 * here the moment it is set.
	 *
	 * @var string
	 */
	public const LOCKED_VERB = NoteLockService::LOCKED_VERB;

	/**
	 * The lock rules of a note, built from this service's own collaborators.
	 *
	 * @var NoteLockService
	 */
	private readonly NoteLockService $lockService;

	/**
	 * How many notes are read per page when every note on an object is walked.
	 *
	 * `deleteNotesForObject()` has to name the comments before they go, and
	 * `getForObject()` is paged, so an object with four hundred notes would
	 * otherwise leave three hundred and fifty histories behind.
	 *
	 * @var integer
	 */
	private const SWEEP_PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param ICommentsManager                   $commentsManager Comments manager for CRUD operations
	 * @param NoteAccounts                       $accounts        User session and user manager
	 * @param TimelineVisibilityServiceInterface $visibility      Normalises the internal/public flag
	 * @param NoteVersionService                 $versions        Keeps and reads what a note said before
	 * @param NoteLockService|null               $lock            The lock rules of a note; built from these collaborators when omitted
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICommentsManager $commentsManager,
		private readonly NoteAccounts $accounts,
		private readonly TimelineVisibilityServiceInterface $visibility,
		private readonly NoteVersionService $versions,
		private readonly ?NoteLockService $lock = null,
	) {
		$this->lockService = ($lock ?? new NoteLockService(commentsManager: $commentsManager, accounts: $accounts));
	}//end __construct()

	/**
	 * Get the first page of notes on an OpenRegister object, whatever their visibility.
	 *
	 * A page or a visibility filter goes through {@see listNotes()}.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return array Array of note arrays in JSON-friendly format
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function getNotesForObject(string $objectUuid): array {
		return $this->listNotes(query: new ObjectNotesQuery(objectUuid: $objectUuid, page: new ListQuery(limit: 50, offset: 0)));
	}//end getNotesForObject()

	/**
	 * One page of the notes on an object, with their edit summaries, filtered by visibility.
	 *
	 * @param ObjectNotesQuery $query The object, the page and the visibility filter.
	 *
	 * @return array<int, array<string, mixed>> The notes.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function listNotes(ObjectNotesQuery $query): array {
		$comments = $this->commentsManager->getForObject(
			self::OBJECT_TYPE,
			$query->objectUuid,
			$query->page->limit,
			($query->page->offset ?? 0)
		);

		$notes = [];
		$pageIds = $this->replies()->idsOf(comments: $comments);
		foreach ($comments as $comment) {
			$notes[] = $this->commentToArray(comment: $comment, knownIds: $pageIds);
		}

		// One query for the whole page rather than one per note: the edit
		// marker is the cheapest thing on the row and must not cost fifty
		// round trips to draw.
		$notes = $this->withEditSummaries(notes: $notes);

		return $this->visibility->filterRows(rows: $notes, filter: $query->visibility);
	}//end listNotes()

	/**
	 * Read one note by its id.
	 *
	 * The caller needs the value a note carries BEFORE it writes a new one, so
	 * the audit entry can name both sides of the move.
	 *
	 * @param int $noteId The ID of the note
	 *
	 * @return array The note in JSON-friendly format
	 *
	 * @throws Exception If the note is not found
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function getNote(int $noteId): array {
		try {
			$comment = $this->commentsManager->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found');
		}

		$notes = $this->withEditSummaries(notes: [$this->commentToArray(comment: $comment)]);

		return $notes[0];
	}//end getNote()

	/**
	 * List what a note used to say, newest first.
	 *
	 * Reading the history is reading the note: a caller that may see the note
	 * may see the texts it replaced, so the guard is the one the note list
	 * already applies and nothing further is checked here.
	 *
	 * @param int $noteId The note whose history is read
	 *
	 * @return array<int, array<string, mixed>> The versions, newest first
	 *
	 * @throws Exception If the note is not found
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function noteVersions(int $noteId): array {
		try {
			$this->commentsManager->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found');
		}

		return $this->versions->versions(noteId: $noteId);
	}//end noteVersions()

	/**
	 * Record on the object that one of its notes was rewritten.
	 *
	 * The object is the caller's, which is why this is not folded into
	 * {@see editNote()}: the controller holds the object, this service holds
	 * the history, and the audit entry needs both.
	 *
	 * @param ObjectEntityInterface $object The object the note hangs on
	 * @param array<string, mixed>  $note   The rewritten note as editNote() returned it (its id and versionCount)
	 *
	 * @return bool True when an audit entry was written
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function auditEdit(ObjectEntityInterface $object, array $note): bool {
		return $this->versions->auditEdit(object: StoredEntity::object(object: $object), note: $note);
	}//end auditEdit()

	/**
	 * Merge each note's edit summary onto it.
	 *
	 * @param array<int, array<string, mixed>> $notes The notes to enrich
	 *
	 * @return array<int, array<string, mixed>> The same notes, carrying their summary
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function withEditSummaries(array $notes): array {
		$ids = [];
		foreach ($notes as $note) {
			$ids[] = (int)($note['id'] ?? 0);
		}

		$summaries = $this->versions->summaries(noteIds: $ids);

		$enriched = [];
		foreach ($notes as $note) {
			$summary = ($summaries[(int)($note['id'] ?? 0)] ?? []);
			$enriched[] = array_merge($note, $summary);
		}

		return $enriched;
	}//end withEditSummaries()

	/**
	 * Create a new note on an OpenRegister object as the session user.
	 *
	 * @param NoteDraft $draft The object, the message and the visibility (`internal` or `public`;
	 *                         anything else, including null, stays internal)
	 *
	 * @return array The created note in JSON-friendly format
	 *
	 * @throws Exception If no user is logged in
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function createNote(NoteDraft $draft): array {
		return $this->postNote(draft: $draft, author: $this->sessionAuthor());
	}//end createNote()

	/**
	 * Write a note on an object under the given author.
	 *
	 * An access link is not an account, and the whole value of the audit trail
	 * is that a comment left through a link says so. Nextcloud's comments carry
	 * an actor TYPE beside the actor id for exactly this reason, so a note left
	 * through a link is written as the link rather than as whoever minted it.
	 * The author is decided by the caller and is visible at that call site.
	 *
	 * @param NoteDraft  $draft  The object, the text and the requested visibility.
	 * @param NoteAuthor $author The actor the note is filed under, e.g. type `users` or `openregister_links`.
	 *
	 * @throws Exception If the author is blank.
	 *
	 * @return array<string, mixed> The created note.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function postNote(NoteDraft $draft, NoteAuthor $author): array {
		if (trim($author->type) === '' || trim($author->id) === '') {
			throw new Exception('A note needs an actor');
		}

		$comment = $this->commentsManager->create(trim($author->type), trim($author->id), self::OBJECT_TYPE, $draft->objectUuid);

		$visibility = $draft->visibility;
		if ($draft->parentId !== null) {
			$visibility = $this->replies()->attach(reply: $comment, draft: $draft);
		}

		$comment->setMessage($draft->message);
		$comment->setVerb('comment');
		$this->applyVisibility(comment: $comment, visibility: $visibility);
		$this->commentsManager->save($comment);

		return $this->commentToArray(comment: $comment);
	}//end postNote()

	/**
	 * Where a note sits in a conversation, and the checks a reply passes.
	 *
	 * @return NoteReplies
	 */
	private function replies(): NoteReplies {
		return new NoteReplies(commentsManager: $this->commentsManager, visibility: $this->visibility);
	}//end replies()

	/**
	 * The session user as a note author.
	 *
	 * @throws Exception If no user is logged in.
	 *
	 * @return NoteAuthor The author.
	 */
	private function sessionAuthor(): NoteAuthor {
		$user = $this->accounts->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return new NoteAuthor(type: 'users', id: $user->getUID());
	}//end sessionAuthor()

	/**
	 * Edit a note's text and/or visibility.
	 *
	 * Rewriting the message is the author's own right, or the right of
	 * somebody who manages the object: a note is a signed statement, so
	 * `update` on the object is deliberately not enough and the caller passes
	 * its `manage` verdict in rather than this service guessing at one.
	 * Moving the note across the counter is a different right again, which is
	 * why a visibility-only write does not ask who wrote the note.
	 *
	 * The text the note is losing is kept as a version BEFORE the comment is
	 * saved: after the save it no longer exists anywhere. A locked note
	 * refuses every edit.
	 *
	 * @param int      $noteId The note to edit.
	 * @param NoteEdit $edit   The new text, the new visibility and the manage right.
	 *
	 * @throws Exception If no user is logged in or the note does not exist.
	 * @throws NoteLockedException If the note is locked.
	 * @throws NoteEditForbiddenException If the editor may not rewrite the text.
	 *
	 * @return array<string, mixed> The updated note.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function editNote(int $noteId, NoteEdit $edit): array {
		$user = $this->accounts->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		$comment = $this->loadComment(noteId: $noteId);
		if ($this->lockService->isLocked(comment: $comment) === true) {
			throw new NoteLockedException(noteId: $noteId);
		}

		if ($edit->message !== null) {
			if ($comment->getActorId() !== $user->getUID() && $edit->mayManage === false) {
				throw new NoteEditForbiddenException();
			}

			$this->versions->record(previous: $comment, editedBy: $user->getUID());

			$comment->setMessage($edit->message);
		}

		if ($edit->visibility !== null) {
			$this->applyVisibility(comment: $comment, visibility: $edit->visibility);
		}

		$this->commentsManager->save($comment);

		$notes = $this->withEditSummaries(notes: [$this->commentToArray(comment: $comment)]);

		return $notes[0];
	}//end editNote()

	/**
	 * Lock a note: from now on nobody can edit or delete it. There is no unlock.
	 *
	 * Only the note's author, or someone who manages the object, may lock it.
	 * Who locked it and when go in the comment's metadata, and the verb
	 * becomes {@see self::LOCKED_VERB}, which {@see editNote()} and
	 * {@see deleteNote()} refuse. Locking a locked note changes nothing.
	 *
	 * @param int  $noteId    The note to lock.
	 * @param bool $mayManage Whether the caller manages the object the note hangs on.
	 *
	 * @throws Exception If no user is logged in or the note does not exist.
	 * @throws NoteEditForbiddenException If the caller is neither the author nor a manager of the object.
	 *
	 * @return array<string, mixed> The locked note.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function lockNote(int $noteId, bool $mayManage): array {
		$comment = $this->lockService->apply(noteId: $noteId, mayManage: $mayManage);

		return $this->withEditSummaries(notes: [$this->commentToArray(comment: $comment)])[0];
	}//end lockNote()

	/**
	 * Write the note-locked entry on the object's audit trail.
	 *
	 * @param ObjectEntityInterface $object The object the note hangs on
	 * @param array<string, mixed>  $note   The locked note as lockNote() returned it
	 *
	 * @return bool True when an audit entry was written
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function auditLock(ObjectEntityInterface $object, array $note): bool {
		return $this->versions->auditLock(object: StoredEntity::object(object: $object), note: $note);
	}//end auditLock()

	/**
	 * Write the flag onto a comment, leaving the rest of its metadata alone.
	 *
	 * @param IComment $comment The comment being written
	 * @param string|null $visibility The requested value; anything unknown lands as internal
	 *
	 * @return void
	 */
	private function applyVisibility(IComment $comment, ?string $visibility): void {
		$metaData = $comment->getMetaData();
		if (is_array($metaData) === false) {
			$metaData = [];
		}

		$metaData[self::VISIBILITY_KEY] = $this->visibility->normalise(value: $visibility);
		$comment->setMetaData($metaData);
	}//end applyVisibility()

	/**
	 * Read the flag off a comment, defaulting to internal.
	 *
	 * @param IComment $comment The comment being read
	 *
	 * @return string Either `internal` or `public`
	 */
	private function readVisibility(IComment $comment): string {
		$metaData = $comment->getMetaData();
		$stored = null;
		if (is_array($metaData) === true && isset($metaData[self::VISIBILITY_KEY]) === true
			&& is_string($metaData[self::VISIBILITY_KEY]) === true
		) {
			$stored = $metaData[self::VISIBILITY_KEY];
		}

		return $this->visibility->normalise(value: $stored);
	}//end readVisibility()

	/**
	 * Refuse a note that does not hang on the given object.
	 *
	 * @param string $objectUuid The object the caller addressed.
	 * @param int    $noteId     The note the caller named.
	 *
	 * @return void
	 *
	 * @throws Exception With code 404 when the note is elsewhere or does not exist.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function requireNoteOf(string $objectUuid, int $noteId): void {
		try {
			$comment = $this->commentsManager->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found', 404);
		}

		if ($comment->getObjectType() !== self::OBJECT_TYPE || $comment->getObjectId() !== $objectUuid) {
			throw new Exception('Note not found', 404);
		}
	}//end requireNoteOf()

	/**
	 * Delete a note by its ID.
	 *
	 * @param int $noteId The ID of the note to delete
	 *
	 * @return void
	 *
	 * @throws Exception If the note is not found
	 * @throws NoteLockedException If the note is locked.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-9
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function deleteNote(int $noteId): void {
		// A locked note is the record of what was said; it is not removed.
		if ($this->lockService->isLocked(comment: $this->loadComment(noteId: $noteId)) === true) {
			throw new NoteLockedException(noteId: $noteId);
		}

		$this->commentsManager->delete((string)$noteId);

		// The note is gone, so its history has nothing left to belong to.
		$this->versions->forget(noteIds: [$noteId]);
	}//end deleteNote()

	/**
	 * A note by id, or a 404 when there is none.
	 *
	 * @param int $noteId The note id.
	 *
	 * @return IComment The comment that is the note.
	 *
	 * @throws Exception With code 404 when the note does not exist.
	 */
	private function loadComment(int $noteId): IComment {
		try {
			return $this->commentsManager->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found', 404);
		}
	}//end loadComment()

	/**
	 * Count the notes on an object.
	 *
	 * Counted at the source rather than by taking the length of a paged read:
	 * {@see getNotesForObject()} returns at most its `$limit`, so counting
	 * through it would report 50 for an object with 400 notes and a
	 * destruction preview would promise the wrong number.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object.
	 *
	 * @return int The number of notes.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countNotesForObject(string $objectUuid): int {
		return $this->commentsManager->getNumberOfCommentsForObject(
			self::OBJECT_TYPE,
			$objectUuid
		);
	}//end countNotesForObject()

	/**
	 * Delete all notes for an OpenRegister object.
	 *
	 * Used for cleanup when an object is deleted.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-9
	 */
	public function deleteNotesForObject(string $objectUuid): void {
		// Name the notes before they go: after `deleteCommentsAtObject()`
		// there is no id left to delete a history by, and a prior text that
		// outlives the object it was written on is exactly the row a
		// retention review asks about.
		$this->versions->forget(noteIds: $this->noteIdsForObject(objectUuid: $objectUuid));

		$this->commentsManager->deleteCommentsAtObject(
			self::OBJECT_TYPE,
			$objectUuid
		);
	}//end deleteNotesForObject()

	/**
	 * Every note id on an object, walked page by page.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return int[] The note ids
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	private function noteIdsForObject(string $objectUuid): array {
		$ids = [];
		$offset = 0;

		do {
			$comments = $this->commentsManager->getForObject(
				self::OBJECT_TYPE,
				$objectUuid,
				self::SWEEP_PAGE,
				$offset
			);

			foreach ($comments as $comment) {
				$ids[] = (int)$comment->getId();
			}

			$offset += self::SWEEP_PAGE;
			$pageSize = count($comments);
		} while ($pageSize === self::SWEEP_PAGE);

		return $ids;
	}//end noteIdsForObject()

	/**
	 * Convert an IComment to a JSON-friendly array.
	 *
	 * @param IComment          $comment  The comment to convert
	 * @param array<int, mixed> $knownIds Ids of notes known to exist (the page being read), sparing a lookup per reply
	 *
	 * @return array The note in JSON-friendly format
	 */
	private function commentToArray(IComment $comment, array $knownIds=[]): array {
		$actorId = $comment->getActorId();
		$actorDisplayName = $actorId;

		// Resolve the display name from the user manager.
		$actorUser = $this->accounts->userManager->get($actorId);
		if ($actorUser !== null) {
			$actorDisplayName = $actorUser->getDisplayName();
		}

		// Check if the current user is the author.
		$isCurrentUser = false;
		$currentUser = $this->accounts->userSession->getUser();
		if ($currentUser !== null) {
			$isCurrentUser = $currentUser->getUID() === $actorId;
		}

		return [
			'id' => (int)$comment->getId(),
			'message' => $comment->getMessage(),
			'actorType' => $comment->getActorType(),
			'actorId' => $actorId,
			'actorDisplayName' => $actorDisplayName,
			'createdAt' => $comment->getCreationDateTime()->format('c'),
			'isCurrentUser' => $isCurrentUser,
			'visibility' => $this->readVisibility(comment: $comment),
			'locked' => $this->lockService->isLocked(comment: $comment),
			'lockedBy' => $this->lockService->lockedBy(comment: $comment)['by'],
			'lockedAt' => $this->lockService->lockedBy(comment: $comment)['at'],
			// The defaults of a note nobody has edited. Every read that can
			// afford the query replaces them through
			// {@see withEditSummaries()}; a note that has just been created
			// carries them as they stand, which is the truth about it.
			'editedAt' => null,
			'editedBy' => null,
			'editedByDisplayName' => null,
			'versionCount' => 0,
		] + $this->replies()->fields(comment: $comment, knownIds: $knownIds);
	}//end commentToArray()

}//end class
