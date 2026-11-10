<?php

/**
 * OpenRegister FileRestrictionRequest.
 *
 * One request to restrict who may read a file: the file, the users and
 * groups that keep access, and why, as the caller gave them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

/**
 * One request to restrict a file, before its principals and reason are checked.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
final class FileRestrictionRequest {
	/**
	 * Constructor.
	 *
	 * @param int               $fileId The file to restrict.
	 * @param array<int, mixed> $users  User ids that keep access, unchecked.
	 * @param array<int, mixed> $groups Group ids that keep access, unchecked.
	 * @param string            $reason Why the file is restricted, untrimmed.
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
	 */
	public function __construct(
		public readonly int $fileId,
		public readonly array $users,
		public readonly array $groups,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
