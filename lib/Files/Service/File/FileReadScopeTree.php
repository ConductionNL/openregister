<?php

/**
 * OpenRegister file read scope tree
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\IRootFolder;
use OCP\IUserSession;

/**
 * The caller and the file tree a file-search read scope resolves hits in.
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
 */
final class FileReadScopeTree {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IRootFolder  $rootFolder  The Nextcloud file tree.
	 * @param IUserSession $userSession The signed-in caller.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
