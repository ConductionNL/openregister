<?php

/**
 * OpenRegister InvalidNoteParentException
 *
 * Thrown when a reply names a parent note that is not on the same object, does
 * not exist, or is not visible to the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A reply whose parent note cannot be replied to from here.
 *
 * The three causes share one message on purpose: telling a caller that a note
 * exists elsewhere, or exists but is hidden from them, says more than they may
 * know. Callers answer it with 422.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notes-replies-by-parent/specs/object-interactions/spec.md#requirement-a-note-can-reply-to-another-note-on-the-same-record
 */
class InvalidNoteParentException extends InvalidArgumentException {

	/**
	 * The sentence every refused reply is answered with.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The note you reply to is not on this record.';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(message: self::MESSAGE);
	}//end __construct()
}//end class
