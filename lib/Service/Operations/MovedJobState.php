<?php

/**
 * MovedJobState: carries the state OpenRegister keeps per job class to a moved class.
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

use OCA\OpenRegister\Db\JobRunMapperInterface;

/**
 * Besides Nextcloud's job list, OpenRegister keys two things by job class: the
 * administered schedule (JobScheduleService) and the run log (JobRunMapperInterface).
 * When a job class moves, both follow it, so the schedule an administrator set
 * and the run history stay with the job.
 */
class MovedJobState implements MovedJobStateInterface {

	/**
	 * Constructor.
	 *
	 * @param JobScheduleService $schedules The administered schedules.
	 * @param JobRunMapperInterface       $runs      The run log.
	 */
	public function __construct(
		private readonly JobScheduleService $schedules,
		private readonly JobRunMapperInterface $runs,
	) {
	}//end __construct()

	/**
	 * Move the schedule and the run history from the old class name to the new one.
	 *
	 * @param string $from The old class name.
	 * @param string $to   The new class name.
	 *
	 * @return void
	 *
	 * @spec exclude Upgrade plumbing for moved job classes; no capability spec covers it.
	 */
	public function carry(string $from, string $to): void {
		$this->schedules->moveSchedule(from: $from, to: $to);
		$this->runs->renameJobClass(from: $from, to: $to);

	}//end carry()
}//end class
