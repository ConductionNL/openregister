<?php

/**
 * OpenRegister NoteEdit.
 *
 * One edit of an existing note: the new text, the new visibility and whether the editor manages the object.
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
 * One edit of an existing note: the new text, the new visibility and whether the editor manages the object.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class NoteEdit {
	/**
	 * Constructor.
	 *
	 * @param string|null $message    New text; null keeps the text.
	 * @param string|null $visibility New visibility; null keeps the visibility.
	 * @param bool        $mayManage  Whether the editor may rewrite another user's note.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ?string $message = null,
		public readonly ?string $visibility = null,
		public readonly bool $mayManage = false,
	) {
	}//end __construct()
}//end class
