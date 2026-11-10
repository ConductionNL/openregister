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

/**
 * Every public method of NoteService. Callers depend on this interface, not on the class.
 */
interface NoteServiceInterface {

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
	): array;

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
	 * {@see updateNote()}: the controller holds the object, this service holds
	 * the history, and the audit entry needs both.
	 *
	 * @param ObjectEntityInterface $object The object the note hangs on
	 * @param int $noteId The note that was rewritten
	 * @param int $versions The number of versions the note now has
	 *
	 * @return bool True when an audit entry was written
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function auditEdit(ObjectEntityInterface $object, int $noteId, int $versions): bool;

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
	public function createNote(string $objectUuid, string $message, ?string $visibility = null): array;

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
	): array;

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
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function updateNote(
		int $noteId,
		?string $message = null,
		?string $visibility = null,
		bool $mayManage = false,
	): array;

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
