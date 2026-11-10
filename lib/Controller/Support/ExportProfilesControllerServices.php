<?php

/**
 * OpenRegister ExportProfilesController services
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

use OCA\OpenRegister\Service\Export\ExportProfileServiceInterface;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of ExportProfilesController, wired by DI autowiring.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportProfilesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ExportProfileServiceInterface $service Profile administration and runs.
	 * @param IUserSession $userSession Current-user session.
	 * @param IGroupManager $groupManager Group manager for the admin check.
	 * @param ExportRunRecorder $exportRuns Records the export this run served.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ExportProfileServiceInterface $service,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly ExportRunRecorder $exportRuns,
	) {
	}//end __construct()
}//end class
