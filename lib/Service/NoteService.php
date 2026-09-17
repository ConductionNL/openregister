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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Exception\NoteLockedException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * NoteService wraps ICommentsManager for OpenRegister object notes.
 *
 * Provides methods to create, list, and delete notes (comments)
 * linked to OpenRegister objects using the objectType "openregister".
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class NoteService {

	/**
	 * Comments manager for note operations.
	 *
	 * @var ICommentsManager
	 */
	private readonly ICommentsManager $commentsManager;

	/**
	 * User session for getting current user.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * User manager for resolving display names.
	 *
	 * @var IUserManager
	 */
	private readonly IUserManager $userManager;

	/**
	 * Logger for error reporting.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

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
	public const LOCKED_VERB = 'note-locked';

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
	 * @param ICommentsManager $commentsManager Comments manager for CRUD operations
	 * @param IUserSession $userSession User session for current user context
	 * @param IUserManager $userManager User manager for display name resolution
	 * @param LoggerInterface $logger Logger for error reporting
	 * @param TimelineVisibilityService $visibility Normalises the internal/public flag
	 * @param NoteVersionService $versions Keeps and reads what a note said before
	 *
	 * @return void
	 */
	public function __construct(
		ICommentsManager $commentsManager,
		IUserSession $userSession,
		IUserManager $userManager,
		LoggerInterface $logger,
		private readonly TimelineVisibilityService $visibility,
		private readonly NoteVersionService $versions,
	) {
		$this->commentsManager = $commentsManager;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Get notes for a specific OpenRegister object.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 * @param int $limit Maximum number of notes to return (default 50)
	 * @param int $offset Number of notes to skip (default 0)
	 * @param string|null $visibility Keep only notes carrying this flag; null returns every note
	 *
	 * @return array Array of note arrays in JSON-friendly format
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function getNotesForObject(
		string $objectUuid,
		int $limit = 50,
		int $offset = 0,
		?string $visibility = null,
	): array {
		$comments = $this->commentsManager->getForObject(
			self::OBJECT_TYPE,
			$objectUuid,
			$limit,
			$offset
		);

		$notes = [];
		foreach ($comments as $comment) {
			$notes[] = $this->commentToArray(comment: $comment);
		}

		// One query for the whole page rather than one per note: the edit
		// marker is the cheapest thing on the row and must not cost fifty
		// round trips to draw.
		$notes = $this->withEditSummaries(notes: $notes);

		return $this->visibility->filterRows(rows: $notes, filter: $visibility);
	}//end getNotesForObject()

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
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
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
	 * {@see updateNote()}: the controller holds the object, this service holds
	 * the history, and the audit entry needs both.
	 *
	 * @param ObjectEntity $object The object the note hangs on
	 * @param int $noteId The note that was rewritten
	 * @param int $versions The number of versions the note now has
	 *
	 * @return bool True when an audit entry was written
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function auditEdit(ObjectEntity $object, int $noteId, int $versions): bool {
		return $this->versions->auditEdit(object: $object, noteId: $noteId, versions: $versions);
	}//end auditEdit()

	/**
	 * Merge each note's edit summary onto it.
	 *
	 * @param array<int, array<string, mixed>> $notes The notes to enrich
	 *
	 * @return array<int, array<string, mixed>> The same notes, carrying their summary
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
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
	 * Create a new note on an OpenRegister object.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 * @param string $message The note message content
	 * @param string|null $visibility `internal` or `public`; anything else, including null, stays internal
	 *
	 * @return array The created note in JSON-friendly format
	 *
	 * @throws Exception If no user is logged in
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function createNote(string $objectUuid, string $message, ?string $visibility = null): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		return $this->createNoteAs(
			objectUuid: $objectUuid,
			message: $message,
			actorType: 'users',
			actorId: $user->getUID(),
			visibility: $visibility
		);
	}//end createNote()

	/**
	 * Create a note attributed to a principal that is not a session user.
	 *
	 * An access link is not an account, and the whole value of the audit trail
	 * is that a comment left through a link says so. Nextcloud's comments carry
	 * an actor TYPE beside the actor id for exactly this reason, so the note is
	 * written as the link rather than as whoever minted it.
	 *
	 * The actor is an argument rather than something this method reaches for:
	 * the identity that will sit on the note is decided by the caller and is
	 * visible at that call site.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 * @param string $message The note message content
	 * @param string $actorType The comment actor type, e.g. `users` or `openregister_links`
	 * @param string $actorId The actor id within that type
	 * @param string|null $visibility `internal` or `public`; anything else, including null, stays internal
	 *
	 * @return array The created note in JSON-friendly format
	 *
	 * @throws Exception When the actor is not named.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function createNoteAs(
		string $objectUuid,
		string $message,
		string $actorType,
		string $actorId,
		?string $visibility = null,
	): array {
		if (trim($actorType) === '' || trim($actorId) === '') {
			throw new Exception('A note needs an actor');
		}

		$comment = $this->commentsManager->create(
			trim($actorType),
			trim($actorId),
			self::OBJECT_TYPE,
			$objectUuid
		);

		$comment->setMessage($message);
		$comment->setVerb('comment');
		$this->applyVisibility(comment: $comment, visibility: $visibility);
		$this->commentsManager->save($comment);

		return $this->commentToArray(comment: $comment);
	}//end createNoteAs()

	/**
	 * Update an existing note's message.
	 *
	 * Rewriting the message is the author's own right, or the right of
	 * somebody who manages the object: a note is a signed statement, so
	 * `update` on the object is deliberately not enough and the caller passes
	 * its `manage` verdict in rather than this service guessing at one.
	 * Moving the note across the counter is a different right again, which is
	 * why a visibility-only write does not ask who wrote the note.
	 *
	 * The text the note is losing is kept as a version BEFORE the comment is
	 * saved: after the save it no longer exists anywhere.
	 *
	 * @param int $noteId The ID of the note to update
	 * @param string|null $message The new message content, or null to leave it alone
	 * @param string|null $visibility The new flag, or null to leave it alone
	 * @param bool $mayManage Whether the caller holds `manage` on the object the note hangs on
	 *
	 * @return array The updated note in JSON-friendly format
	 *
	 * @throws NoteLockedException If the note is locked
	 * @throws NoteEditForbiddenException If the caller is neither the author nor a manager
	 * @throws Exception If the note is not found or nobody is logged in
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $mayManage is a permission verdict the caller resolved, not a mode switch.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function updateNote(
		int $noteId,
		?string $message = null,
		?string $visibility = null,
		bool $mayManage = false,
	): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		try {
			$comment = $this->commentsManager->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found');
		}

		if ($comment->getVerb() === self::LOCKED_VERB) {
			throw new NoteLockedException(noteId: $noteId);
		}

		if ($message !== null) {
			if ($comment->getActorId() !== $user->getUID() && $mayManage === false) {
				throw new NoteEditForbiddenException();
			}

			$this->versions->record(
				noteId: $noteId,
				previousMessage: $comment->getMessage(),
				author: $comment->getActorId(),
				authorType: $comment->getActorType(),
				editedBy: $user->getUID()
			);

			$comment->setMessage($message);
		}

		if ($visibility !== null) {
			$this->applyVisibility(comment: $comment, visibility: $visibility);
		}

		$this->commentsManager->save($comment);

		$notes = $this->withEditSummaries(notes: [$this->commentToArray(comment: $comment)]);

		return $notes[0];
	}//end updateNote()

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
	 * Delete a note by its ID.
	 *
	 * @param int $noteId The ID of the note to delete
	 *
	 * @return void
	 *
	 * @throws Exception If the note is not found
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-9
	 */
	public function deleteNote(int $noteId): void {
		try {
			$this->commentsManager->get((string)$noteId);
			$this->commentsManager->delete((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found');
		}

		// The note is gone, so its history has nothing left to belong to.
		$this->versions->forget(noteIds: [$noteId]);
	}//end deleteNote()

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
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
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
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
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
	 * Map an IComment to a JSON-friendly array.
	 *
	 * @param IComment $comment The comment to convert
	 *
	 * @return array The note in JSON-friendly format
	 */
	private function commentToArray(IComment $comment): array {
		$actorId = $comment->getActorId();
		$actorDisplayName = $actorId;

		// Resolve the display name from the user manager.
		$actorUser = $this->userManager->get($actorId);
		if ($actorUser !== null) {
			$actorDisplayName = $actorUser->getDisplayName();
		}

		// Check if the current user is the author.
		$isCurrentUser = false;
		$currentUser = $this->userSession->getUser();
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
			'locked' => ($comment->getVerb() === self::LOCKED_VERB),
			// The defaults of a note nobody has edited. Every read that can
			// afford the query replaces them through
			// {@see withEditSummaries()}; a note that has just been created
			// carries them as they stand, which is the truth about it.
			'editedAt' => null,
			'editedBy' => null,
			'editedByDisplayName' => null,
			'versionCount' => 0,
		];
	}//end commentToArray()
}//end class
