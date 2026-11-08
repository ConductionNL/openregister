<?php

/**
 * OpenRegister document processing platform services
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
use OCP\IL10N;
use OCP\IUserSession;

/**
 * The Nextcloud platform services of the document processing handler.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class DocumentProcessingPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IRootFolder  $rootFolder  Root folder for file access.
	 * @param IUserSession $userSession User session for getting current user.
	 * @param IL10N|null   $l10n        Acting-user localisation for the placeholder TYPE label; null emits the raw English label.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly IUserSession $userSession,
		public readonly ?IL10N $l10n = null,
	) {
	}//end __construct()
}//end class
