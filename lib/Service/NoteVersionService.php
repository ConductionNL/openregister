<?php

/**
 * NoteVersionService — what a note said before, and who changed it.
 *
 * A note is a Nextcloud comment, and `ICommentsManager` has no history: the
 * message column is the live text and an edit overwrites it. This service
 * owns the small table beside the comment that keeps every prior text, the
 * summary a note read carries (`editedAt`, `editedBy`, `versionCount`), the
 * cleanup that runs with a delete, and the `note.edited` entry on the
 * object's own audit trail.
 *
 * The trail records the fact, the versions hold the text: that is why no note
 * text is ever passed into the audit context here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\NoteVersion;
use OCA\OpenRegister\Db\NoteVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps and reads the prior texts of an edited note.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class NoteVersionService {

	/**
	 * The audit action written when a note's text is replaced.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'note.edited';

	/**
	 * Constructor.
	 *
	 * @param NoteVersionMapper $mapper      Reads and writes the version rows.
	 * @param AuditTrailMapper  $auditTrail  Writes the entry on the object.
	 * @param IUserManager      $userManager Resolves display names for the version list.
	 * @param LoggerInterface   $logger      Logger for the paths that must not lose the edit.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly NoteVersionMapper $mapper,
		private readonly AuditTrailMapper $auditTrail,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep the text a note is about to lose.
	 *
	 * Called before the comment is overwritten, which is the only moment the
	 * previous text still exists. It throws rather than logging: a version
	 * that silently failed to write would leave an edit with no history and
	 * a note claiming, through its own `versionCount`, that there is none.
	 *
	 * @param integer $noteId          The note being rewritten.
	 * @param string  $previousMessage The text being replaced.
	 * @param string  $author          The actor the replaced text was attributed to.
	 * @param string  $authorType      That actor's type, e.g. `users`.
	 * @param string  $editedBy        The user replacing the text.
	 *
	 * @return NoteVersion The stored version.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function record(
		int $noteId,
		string $previousMessage,
		string $author,
		string $authorType,
		string $editedBy,
	): NoteVersion {
		$version = new NoteVersion();
		$version->setCommentId($noteId);
		$version->setMessage($previousMessage);
		$version->setAuthor($author);
		$version->setAuthorType($authorType);
		$version->setEditedBy($editedBy);
		$version->setEditedAt(new DateTime());

		return $this->mapper->insert($version);
	}//end record()

	/**
	 * List a note's versions, newest first.
	 *
	 * @param integer $noteId The note whose history is read.
	 *
	 * @return array<int, array<string, mixed>> The versions, newest first.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function versions(int $noteId): array {
		$rows = [];
		foreach ($this->mapper->findByComment(commentId: $noteId) as $version) {
			$row = $version->jsonSerialize();
			$row['authorDisplayName'] = $this->displayName(userId: $version->getAuthor());
			$row['editedByDisplayName'] = $this->displayName(userId: $version->getEditedBy());
			$rows[] = $row;
		}

		return $rows;
	}//end versions()

	/**
	 * The edit summary of several notes, keyed by note id.
	 *
	 * Every id asked for comes back, so a caller can merge the summary onto a
	 * note without testing whether the key is there: a note nobody edited
	 * reports `versionCount` 0 and no editor, which is a different statement
	 * from "unknown".
	 *
	 * @param int[] $noteIds The notes to summarise.
	 *
	 * @return array<int, array{editedAt: string|null, editedBy: string|null,
	 *         editedByDisplayName: string|null, versionCount: int}> Keyed by note id.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function summaries(array $noteIds): array {
		$ids = array_values(array_unique(array_map('intval', $noteIds)));
		$empty = [
			'editedAt' => null,
			'editedBy' => null,
			'editedByDisplayName' => null,
			'versionCount' => 0,
		];

		$summaries = [];
		foreach ($ids as $id) {
			$summaries[$id] = $empty;
		}

		if (count($ids) === 0) {
			return $summaries;
		}

		try {
			$stored = $this->mapper->summariesFor(commentIds: $ids);
		} catch (Throwable $e) {
			// A note list must still render when the history cannot be read;
			// reporting "not edited" here is wrong but recoverable, and it is
			// logged rather than swallowed.
			$this->logger->error(
				'[NoteVersionService] Edit summaries for ' . count($ids) . ' notes could not be read',
				['exception' => $e]
			);

			return $summaries;
		}

		foreach ($stored as $id => $summary) {
			$summaries[$id] = [
				'editedAt' => $summary['editedAt'],
				'editedBy' => $summary['editedBy'],
				'editedByDisplayName' => $this->displayName(userId: $summary['editedBy']),
				'versionCount' => $summary['versionCount'],
			];
		}

		return $summaries;
	}//end summaries()

	/**
	 * Drop the versions of the given notes.
	 *
	 * A prior text that outlives the note it belongs to is a record nobody
	 * can reach and nobody erased, so deleting a note deletes its history and
	 * deleting an object deletes the history of every note on it.
	 *
	 * @param int[] $noteIds The notes whose versions go.
	 *
	 * @return integer The number of version rows deleted.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function forget(array $noteIds): int {
		try {
			return $this->mapper->deleteByComments(commentIds: $noteIds);
		} catch (Throwable $e) {
			$this->logger->error(
				'[NoteVersionService] Versions of ' . count($noteIds) . ' deleted notes could not be removed',
				['exception' => $e]
			);

			return 0;
		}
	}//end forget()

	/**
	 * Record on the object that a note was rewritten.
	 *
	 * The entry names the note and the editor and carries no note text: the
	 * trail stays small and the text stays in the versions, which is what
	 * ADR-003 asks of an immutable trail.
	 *
	 * @param ObjectEntity $object   The object the note hangs on.
	 * @param integer      $noteId   The note that was rewritten.
	 * @param integer      $versions The number of versions the note now has.
	 *
	 * @return boolean True when an audit entry was written.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function auditEdit(ObjectEntity $object, int $noteId, int $versions): bool {
		try {
			$this->auditTrail->createAuditTrailEntry(
				object: $object,
				action: self::AUDIT_ACTION,
				context: [
					'noteId' => $noteId,
					'versionCount' => $versions,
				]
			);
		} catch (Throwable $e) {
			// The text has already been replaced and the version already
			// written; losing the trail entry must not lose the edit.
			$this->logger->error(
				'[NoteVersionService] Note ' . $noteId . ' was edited but the audit entry could not be written',
				['exception' => $e]
			);

			return false;
		}

		return true;
	}//end auditEdit()

	/**
	 * Resolve a user id to a display name, falling back to the id.
	 *
	 * @param string|null $userId The actor id, when there is one.
	 *
	 * @return string|null The display name, the id, or null when there is no actor.
	 */
	private function displayName(?string $userId): ?string {
		if ($userId === null || trim($userId) === '') {
			return null;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			return $userId;
		}

		return $user->getDisplayName();
	}//end displayName()
}//end class
