<?php

/**
 * OpenRegister Scheduled report owner services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;

/**
 * Everything a run does on behalf of the report's owner: resolve the owner,
 * run as the owner, write into the owner's Files, and notify the owner.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportOwnerServices {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserManager $userManager         Resolves the owner IUser for impersonation.
	 * @param IUserSession $userSession         Session, swapped to the owner during a run.
	 * @param IRootFolder  $rootFolder          Files root, for owner-folder delivery.
	 * @param IManager     $notificationManager Notification dispatch.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly IUserSession $userSession,
		public readonly IRootFolder $rootFolder,
		public readonly IManager $notificationManager,
	) {
	}//end __construct()
}//end class
