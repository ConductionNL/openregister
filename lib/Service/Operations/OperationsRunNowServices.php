<?php

/**
 * OperationsRunNowServices: the collaborators that start a job by hand.
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
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCP\BackgroundJob\IJobList;
use Psr\Container\ContainerInterface;

/**
 * The job list, the container that builds a job, and the recorder that records the run-now.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
 */
final readonly class OperationsRunNowServices {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IJobList           $jobList   Nextcloud's registered jobs.
	 * @param ContainerInterface $container Resolves a job class to a job.
	 * @param JobRunRecorder     $recorder  Records the run-now, with its cause.
	 */
	public function __construct(
		public IJobList $jobList,
		public ContainerInterface $container,
		public JobRunRecorder $recorder,
	) {
	}//end __construct()
}//end class
