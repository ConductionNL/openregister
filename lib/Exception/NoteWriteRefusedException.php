<?php

/**
 * OpenRegister NoteWriteRefusedException
 *
 * The common parent of the refusals a note write can meet: the note is locked,
 * or the caller may not rewrite it. Each child names the HTTP status it maps
 * to, so a controller answers every refusal with one catch and cannot map a
 * lock to the wrong status.
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

/**
 * A note write that was refused, carrying the HTTP status to answer with.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
 */
abstract class NoteWriteRefusedException extends Exception {

	/**
	 * The HTTP status this refusal maps to. Each child overrides it.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 400;

	/**
	 * The HTTP status a controller answers this refusal with.
	 *
	 * @return integer The status code.
	 *
	 * @spec openspec/changes/note-edit-history/specs/object-interactions/spec.md
	 */
	public function getHttpStatus(): int {
		return static::HTTP_STATUS;
	}//end getHttpStatus()
}//end class
