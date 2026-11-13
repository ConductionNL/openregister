<?php

/**
 * The lock on a note: who may set it, what it writes, and how to read it.
 *
 * A locked note is the record of what was said at a contact moment or in a
 * decision, so once set the lock does not come off and the note cannot be
 * edited or deleted (notes-leaf-rich-text-lock-export, design D-2 and D-3).
 * Only the note's author, or a user who manages the object, may lock it.
 *
 * This holds just the lock rules; NoteService owns the comment, the save and
 * the shape it returns, so the two concerns stay small and testable apart.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use Exception;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;

/**
 * The lock rules of a note.
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
 */
class NoteLockService {

	/**
	 * The comment verb a locked note carries.
	 *
	 * @var string
	 */
	public const LOCKED_VERB = 'note-locked';

	/**
	 * Constructor.
	 *
	 * @param ICommentsManager|null $commentsManager The comments store a lock is read from and written to.
	 * @param NoteAccounts|null     $accounts        The user session the caller is read from.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function __construct(
		private readonly ?ICommentsManager $commentsManager = null,
		private readonly ?NoteAccounts $accounts = null,
	) {
	}//end __construct()

	/**
	 * The metadata key holding who locked a note and when.
	 *
	 * @var string
	 */
	private const LOCK_KEY = 'lock';

	/**
	 * Lock the note by id and save it; answer the comment as it now stands.
	 *
	 * The signed-in user must be the note's author or a manager of the object.
	 * A note that is already locked is answered unchanged and saved again by
	 * nobody. There is no unlock.
	 *
	 * @param int     $noteId    The note to lock.
	 * @param boolean $mayManage Whether the caller manages the object.
	 *
	 * @return IComment The locked note.
	 *
	 * @throws Exception When no user is logged in, or with code 404 when the note does not exist.
	 * @throws NoteEditForbiddenException When the caller may not lock this note.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function apply(int $noteId, bool $mayManage): IComment {
		$user = $this->accounts?->userSession->getUser();
		if ($user === null) {
			throw new Exception('No user logged in');
		}

		try {
			$comment = $this->commentsManager?->get((string)$noteId);
		} catch (CommentsNotFoundException $e) {
			throw new Exception('Note not found', 404);
		}

		if ($comment === null) {
			throw new Exception('Note not found', 404);
		}

		if ($this->isLocked(comment: $comment) === false) {
			$this->lock(comment: $comment, userId: $user->getUID(), mayManage: $mayManage);
			$this->commentsManager?->save($comment);
		}

		return $comment;
	}//end apply()

	/**
	 * Whether the comment is a locked note.
	 *
	 * @param IComment $comment The comment.
	 *
	 * @return boolean True when it is locked.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function isLocked(IComment $comment): bool {
		return $comment->getVerb() === self::LOCKED_VERB;
	}//end isLocked()

	/**
	 * Write the lock onto the comment: the verb and the actor and time.
	 *
	 * The caller must be the note's author or a manager of the object, or the
	 * lock is refused. The comment is mutated but not saved; the caller saves.
	 *
	 * @param IComment $comment   The note.
	 * @param string   $userId    The caller.
	 * @param boolean  $mayManage Whether the caller manages the object.
	 *
	 * @return void
	 *
	 * @throws NoteEditForbiddenException When the caller may not lock this note.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function lock(IComment $comment, string $userId, bool $mayManage): void {
		if ($comment->getActorId() !== $userId && $mayManage === false) {
			throw new NoteEditForbiddenException();
		}

		$metaData = $comment->getMetaData();
		if (is_array($metaData) === false) {
			$metaData = [];
		}

		$metaData[self::LOCK_KEY] = ['by' => $userId, 'at' => gmdate('c')];
		$comment->setMetaData($metaData);
		$comment->setVerb(self::LOCKED_VERB);
	}//end lock()

	/**
	 * Who locked a note and when, or nulls for a note that is not locked.
	 *
	 * @param IComment $comment The comment.
	 *
	 * @return array{by: string|null, at: string|null} The actor and time.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
	 */
	public function lockedBy(IComment $comment): array {
		$metaData = $comment->getMetaData();
		$lock = null;
		if (is_array($metaData) === true) {
			$lock = ($metaData[self::LOCK_KEY] ?? null);
		}

		if ($this->isLocked(comment: $comment) === false || is_array($lock) === false) {
			return ['by' => null, 'at' => null];
		}

		return ['by' => (string)($lock['by'] ?? ''), 'at' => (string)($lock['at'] ?? '')];
	}//end lockedBy()
}//end class
