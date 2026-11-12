<?php

/**
 * OpenRegister file extraction platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The platform services of the file extraction API.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class FileExtractionPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IRootFolder   $rootFolder   Root folder for per-user file access checks
	 * @param IUserSession  $userSession  Active user session for caller identity
	 * @param IGroupManager $groupManager Group manager for admin checks
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
