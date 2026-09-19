<?php

/**
 * OpenRegister NoteLockedException
 *
 * Thrown when an edit or a delete lands on a locked note. A locked note is
 * the record of a contact moment or a decision, and the point of the lock is
 * that it no longer changes, so the write is refused rather than merged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Throwable;

/**
 * Exception thrown when a locked note is edited.
 *
 * Controllers MUST map this to HTTP 423 Locked, per RFC 4918.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */
class NoteLockedException extends NoteWriteRefusedException {

	/**
	 * The HTTP status controllers MUST map this exception to.
	 *
	 * A constant rather than the exception's `code`, matching
	 * {@see LockedException::HTTP_STATUS}: `getCode()` is an application error
	 * code, not an HTTP status.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 423;

	/**
	 * Constructor.
	 *
	 * @param integer        $noteId   The note that refused the write.
	 * @param Throwable|null $previous Previous exception, when there is one.
	 *
	 * @return void
	 */
	public function __construct(private readonly int $noteId, ?Throwable $previous = null) {
		parent::__construct(
			message: 'This note is locked and can no longer be changed',
			code: self::HTTP_STATUS,
			previous: $previous
		);
	}//end __construct()

	/**
	 * The note that refused the write.
	 *
	 * @return integer The note id.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function getNoteId(): int {
		return $this->noteId;
	}//end getNoteId()
}//end class
