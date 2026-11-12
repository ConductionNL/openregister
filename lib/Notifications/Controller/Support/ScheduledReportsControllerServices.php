<?php

/**
 * The services of the scheduled reports API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCP\BackgroundJob\IJobList;

/**
 * The services of the scheduled reports API.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final readonly class ScheduledReportsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ScheduledReportsInterface $service The scheduled reports service.
	 * @param IJobList                  $jobList Queues a run-now job.
	 * @param ControllerSession         $session The session, group and logging bundle.
	 */
	public function __construct(
		public ScheduledReportsInterface $service,
		public IJobList $jobList,
		public ControllerSession $session,
	) {
	}//end __construct()
}//end class
