<?php

/**
 * CallerFileAccess: whether the caller of a file endpoint can open a file.
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
 * The one "can the caller open this file" check of the per-file endpoints.
 *
 * Resolves the file through the caller's own user folder so Nextcloud's
 * share/permission ACLs apply. A file the user cannot access resolves to
 * no node — preventing IDOR where a caller extracts/inspects arbitrary
 * file IDs they do not own.
 */
final class CallerFileAccess {

	/**
	 * Constructor.
	 *
	 * @param FileReadScope|null $fileReadScope The object read rule for files, when wired.
	 * @param IUserSession       $userSession   The caller's session.
	 * @param IRootFolder        $rootFolder    The root folder.
	 */
	public function __construct(
		private readonly ?FileReadScope $fileReadScope,
		private readonly IUserSession $userSession,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * Whether the current session user can access the given Nextcloud file.
	 *
	 * @param int $fileId Nextcloud file ID.
	 *
	 * @return bool True when the file is reachable in the caller's user folder.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md
	 */
	public function mayOpen(int $fileId): bool {
		// An object's file sits in the openregister account's home, never in
		// the caller's own tree: the object's read rule decides it.
		if ($this->fileReadScope !== null) {
			return $this->fileReadScope->mayReadFile(fileId: $fileId);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		return empty($userFolder->getById($fileId)) === false;
	}//end mayOpen()
}//end class
