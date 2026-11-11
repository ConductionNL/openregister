<?php

/**
 * JobScheduleServiceInterface: what callers outside the Service component ask of JobScheduleService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTime;

/**
 * Administered job schedules, as the recorded timed-job base asks them. Only
 * the method that caller calls.
 */
interface JobScheduleServiceInterface {

	/**
	 * Whether a job may run at a moment, per its administered schedule.
	 *
	 * @param string   $jobClass The job class.
	 * @param DateTime $moment   The moment being asked about.
	 *
	 * @return bool True when the schedule allows it.
	 */
	public function mayRun(string $jobClass, DateTime $moment): bool;
}//end interface
