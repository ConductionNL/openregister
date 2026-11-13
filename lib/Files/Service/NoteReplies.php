<?php

/**
 * OpenRegister NoteReplies
 *
 * Where a note sits in a conversation, and the checks a reply passes before it is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

use OCA\OpenRegister\Exception\InvalidNoteParentException;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;

/**
 * Replies between notes: a reply hangs on a note of the same object that its
 * writer may read, never shows more widely than that note, and outlives it.
 *
 * @spec openspec/changes/notes-replies-by-parent/specs/object-interactions/spec.md#requirement-a-note-can-reply-to-another-note-on-the-same-record
 */
final class NoteReplies {

	/**
	 * The comment object type notes are stored under.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param ICommentsManager                   $commentsManager Nextcloud's comments, where notes live.
	 * @param TimelineVisibilityServiceInterface $visibility      Normalises a note's visibility.
	 */
	public function __construct(
		private readonly ICommentsManager $commentsManager,
		private readonly TimelineVisibilityServiceInterface $visibility,
	) {
	}//end __construct()

	/**
	 * Hang a reply on its parent, and say which visibility the reply is written with.
	 *
	 * @param IComment  $reply The reply about to be saved.
	 * @param NoteDraft $draft The reply's draft, naming the parent and the visibility its writer may read.
	 *
	 * @return string|null The reply's visibility: internal under an internal parent, else as requested.
	 *
	 * @throws InvalidNoteParentException When the parent is missing, on another object or hidden from the writer.
	 *
	 * @spec openspec/changes/notes-replies-by-parent/specs/object-interactions/spec.md#requirement-a-note-can-reply-to-another-note-on-the-same-record
	 */
	public function attach(IComment $reply, NoteDraft $draft): ?string {
		$parent = $this->parentOf(draft: $draft);
		$reply->setParentId((string) $draft->parentId);

		// A reply never shows more widely than the note it answers.
		if ($this->visibilityOf(comment: $parent) === TimelineVisibilityServiceInterface::INTERNAL) {
			return TimelineVisibilityServiceInterface::INTERNAL;
		}

		return $draft->visibility;
	}//end attach()

	/**
	 * Where a note sits in a conversation: its parent, its reply count, and
	 * whether the note it answers has since been deleted.
	 *
	 * @param IComment          $comment  The note.
	 * @param array<int, mixed> $knownIds Ids of notes known to exist, sparing a lookup per reply.
	 *
	 * @return array{parentId: int|null, replyCount: int, parentDeleted: bool}
	 *
	 * @spec openspec/changes/notes-replies-by-parent/specs/object-interactions/spec.md#requirement-a-note-can-reply-to-another-note-on-the-same-record
	 */
	public function fields(IComment $comment, array $knownIds=[]): array {
		$parentId = (int) $comment->getParentId();
		$replyCount = (int) $comment->getChildrenCount();
		if ($parentId === 0) {
			return ['parentId' => null, 'replyCount' => $replyCount, 'parentDeleted' => false];
		}

		return [
			'parentId' => $parentId,
			'replyCount' => $replyCount,
			'parentDeleted' => ($this->exists(noteId: $parentId, knownIds: $knownIds) === false),
		];
	}//end fields()

	/**
	 * The ids of a page of comments.
	 *
	 * @param array<int, IComment> $comments The comments.
	 *
	 * @return array<int, int>
	 */
	public function idsOf(array $comments): array {
		return array_map(static fn (IComment $comment): int => (int) $comment->getId(), $comments);
	}//end idsOf()

	/**
	 * The note a reply answers, refused unless it is on the same object and the writer may read it.
	 *
	 * @param NoteDraft $draft The reply.
	 *
	 * @return IComment The parent.
	 *
	 * @throws InvalidNoteParentException When the parent is missing, on another object or hidden from the writer.
	 */
	private function parentOf(NoteDraft $draft): IComment {
		try {
			$parent = $this->commentsManager->get((string) $draft->parentId);
		} catch (CommentsNotFoundException $e) {
			throw new InvalidNoteParentException();
		}

		if ($parent->getObjectType() !== self::OBJECT_TYPE || $parent->getObjectId() !== $draft->objectUuid) {
			throw new InvalidNoteParentException();
		}

		if ($draft->readable !== null && $this->visibilityOf(comment: $parent) !== $this->visibility->normalise(value: $draft->readable)) {
			throw new InvalidNoteParentException();
		}

		return $parent;
	}//end parentOf()

	/**
	 * A note's visibility, as NoteService stores it in the comment's metadata.
	 *
	 * @param IComment $comment The note.
	 *
	 * @return string `internal` or `public`.
	 */
	private function visibilityOf(IComment $comment): string {
		$metaData = $comment->getMetaData();
		$stored = null;
		if (is_array($metaData) === true && is_string($metaData['visibility'] ?? null) === true) {
			$stored = $metaData['visibility'];
		}

		return $this->visibility->normalise(value: $stored);
	}//end visibilityOf()

	/**
	 * Whether a note still exists.
	 *
	 * @param int               $noteId   The note.
	 * @param array<int, mixed> $knownIds Ids already known to exist.
	 *
	 * @return bool
	 */
	private function exists(int $noteId, array $knownIds): bool {
		if (in_array($noteId, $knownIds, true) === true) {
			return true;
		}

		try {
			$this->commentsManager->get((string) $noteId);
		} catch (CommentsNotFoundException $e) {
			return false;
		}

		return true;
	}//end exists()
}//end class
