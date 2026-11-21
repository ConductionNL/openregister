<?php

/**
 * OfficeTokenGrant
 *
 * What one Office WOPI token is issued for: the open request and whether the
 * token may save, with the file id and the name the editor is shown under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * The request and write right one Office token is issued for.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class OfficeTokenGrant {
	/**
	 * Constructor.
	 *
	 * @param OfficeOpenRequest $request  The file, the editor, their display name and the caller's write right.
	 * @param bool              $writable Whether the token may save: the caller's and Office's write right both hold.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public OfficeOpenRequest $request,
		public bool $writable,
	) {
	}//end __construct()

	/**
	 * The file id as the token stores it.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function fileId(): string {
		return (string)$this->request->file->getId();
	}//end fileId()

	/**
	 * The name the editor is shown under: the display name, or the uid when that is empty.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function guestName(): string {
		$guestName = $this->request->displayName;
		if ($guestName === '') {
			$guestName = $this->request->editorUid;
		}

		return $guestName;
	}//end guestName()
}//end class
