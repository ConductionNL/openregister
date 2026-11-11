<?php

/**
 * OpenRegister operations-console controller services
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

use OCA\OpenRegister\Service\OperationsConsoleServiceInterface;
use OCA\OpenRegister\Service\Operations\JobAlertServiceInterface;
use OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface;

/**
 * The services of OperationsConsoleController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/operations-console/spec.md
 */
final class OperationsConsoleControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param OperationsConsoleServiceInterface $console     The read model.
	 * @param OperationsJobsServiceInterface    $jobsService The run history, run now and the schedule.
	 * @param JobAlertServiceInterface          $alerts      The administered failure threshold.
	 * @param ControllerSession                 $session     Names the administrator acting.
	 */
	public function __construct(
		public readonly OperationsConsoleServiceInterface $console,
		public readonly OperationsJobsServiceInterface $jobsService,
		public readonly JobAlertServiceInterface $alerts,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
