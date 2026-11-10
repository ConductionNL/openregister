<?php

/**
 * NoteServiceInterface: the public surface of NoteService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use Exception;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Exception\NoteLockedException;
use OCA\OpenRegister\Files\Service\NoteAuthor;
use OCA\OpenRegister\Files\Service\NoteDraft;
use OCA\OpenRegister\Files\Service\NoteEdit;
use OCA\OpenRegister\Files\Service\ObjectNotesQuery;

/**
 * Every public method of NoteService. Callers depend on this interface, not on the class.
 */
interface NoteServiceInterface {

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
	public function getNotesForObject(string $objectUuid): array;

	/**
	 * One page of the notes on an object, with their edit summaries, filtered by visibility.
	 *
	 * @param ObjectNotesQuery $query The object, the page and the visibility filter.
	 *
	 * @return array<int, array<string, mixed>> The notes.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function listNotes(ObjectNotesQuery $query): array;

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
	public function getNote(int $noteId): array;

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
	public function noteVersions(int $noteId): array;

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
	public function auditEdit(ObjectEntityInterface $object, array $note): bool;

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
	public function createNote(NoteDraft $draft): array;

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
	public function postNote(NoteDraft $draft, NoteAuthor $author): array;

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
	public function editNote(int $noteId, NoteEdit $edit): array;

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
	public function deleteNote(int $noteId): void;

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
	public function countNotesForObject(string $objectUuid): int;

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
	public function deleteNotesForObject(string $objectUuid): void;
}//end interface
