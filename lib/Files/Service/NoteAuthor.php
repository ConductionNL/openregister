<?php

/**
 * OpenRegister NoteAuthor.
 *
 * The actor a note is filed under, as the comments manager stores it.
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

/**
 * The actor a note is filed under, as the comments manager stores it.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class NoteAuthor {
	/**
	 * Constructor.
	 *
	 * @param string $type The actor type, for example 'users' or 'guests'.
	 * @param string $id   The actor id.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
