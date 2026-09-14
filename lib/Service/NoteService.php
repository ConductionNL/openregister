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
	 * Constructor.
	 *
	 * @param ICommentsManager $commentsManager Comments manager for CRUD operations
	 * @param IUserSession $userSession User session for current user context
	 * @param IUserManager $userManager User manager for display name resolution
	 * @param LoggerInterface $logger Logger for error reporting
	 * @param TimelineVisibilityService $visibility Normalises the internal/public flag
	 *
	 * @return void
	 */
	public function __construct(
		ICommentsManager $commentsManager,
		IUserSession $userSession,
		IUserManager $userManager,
		LoggerInterface $logger,
		private readonly TimelineVisibilityService $visibility,
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

		return $this->commentToArray(comment: $comment);
	}//end getNote()

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

		$comment = $this->commentsManager->create(
			'users',
			$user->getUID(),
			self::OBJECT_TYPE,
			$objectUuid
		);

		$comment->setMessage($message);
		$comment->setVerb('comment');
		$this->applyVisibility(comment: $comment, visibility: $visibility);
		$this->commentsManager->save($comment);

		return $this->commentToArray(comment: $comment);
	}//end createNote()

	/**
	 * Update an existing note's message.
	 *
	 * Editing the message stays the author's own right. Moving the note across
	 * the counter is not: it is authorised on the object by the caller, which
	 * is why a visibility-only write does not ask who wrote the note.
	 *
	 * @param int $noteId The ID of the note to update
	 * @param string|null $message The new message content, or null to leave it alone
	 * @param string|null $visibility The new flag, or null to leave it alone
	 *
	 * @return array The updated note in JSON-friendly format
	 *
	 * @throws Exception If the note is not found or the author edits someone else's message
	 *
	 * @spec openspec/changes/timeline-entry-visibility/specs/object-interactions/spec.md
	 */
	public function updateNote(int $noteId, ?string $message = null, ?string $visibility = null): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		try {
			$comment = $this->commentsManager->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found');
		}

		if ($message !== null) {
			if ($comment->getActorId() !== $user->getUID()) {
				throw new Exception('You can only edit your own notes');
			}

			$comment->setMessage($message);
		}

		if ($visibility !== null) {
			$this->applyVisibility(comment: $comment, visibility: $visibility);
		}

		$this->commentsManager->save($comment);

		return $this->commentToArray(comment: $comment);
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
		$this->commentsManager->deleteCommentsAtObject(
			self::OBJECT_TYPE,
			$objectUuid
		);
	}//end deleteNotesForObject()

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
		];
	}//end commentToArray()
}//end class
