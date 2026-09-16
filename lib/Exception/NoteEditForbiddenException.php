<?php

/**
 * OpenRegister NoteEditForbiddenException
 *
 * Thrown when someone who is neither the note's author nor a manager of the
 * object tries to rewrite the note's text. A note is a signed statement by
 * its author, so `update` on the object is not enough to change what it says.
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

use Exception;
use Throwable;

/**
 * Exception thrown when a caller may not rewrite a note.
 *
 * Controllers MUST map this to HTTP 403 Forbidden.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */
class NoteEditForbiddenException extends Exception {

	/**
	 * The HTTP status controllers MUST map this exception to.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 403;

	/**
	 * Constructor.
	 *
	 * @param Throwable|null $previous Previous exception, when there is one.
	 *
	 * @return void
	 */
	public function __construct(?Throwable $previous = null) {
		parent::__construct(
			message: 'Only the author of a note, or someone who manages this object, can change what it says',
			code: self::HTTP_STATUS,
			previous: $previous
		);
	}//end __construct()
}//end class
