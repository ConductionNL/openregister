<?php

/**
 * OpenRegister import preview platform
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

namespace OCA\OpenRegister\Controller\Support;

use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The platform services of the import preview API.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportPreviewPlatform {
	/**
	 * Current-user session.
	 *
	 * @var IUserSession
	 */
	public readonly IUserSession $userSession;

	/**
	 * Group manager.
	 *
	 * @var IGroupManager
	 */
	public readonly IGroupManager $groupManager;

	/**
	 * Bundle the services.
	 *
	 * @param SessionPrincipal $principal The session and group manager, kept as userSession and groupManager.
	 * @param IJobList      $jobList      The background queue.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function __construct(
		SessionPrincipal $principal,
		public readonly IJobList $jobList,
	) {
		$this->userSession  = $principal->userSession;
		$this->groupManager = $principal->groupManager;
	}//end __construct()
}//end class
