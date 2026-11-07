<?php

/**
 * JobRunRecorderInterface: what callers outside the Service component ask of JobRunRecorder.
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

use OCA\OpenRegister\Db\JobRun;
use Throwable;

/**
 * Recording a job run around its execution, as the recorded job bases and
 * their subclasses use it. Only the method those callers call.
 */
interface JobRunRecorderInterface {

	/**
	 * Run the work and record the run, its outcome and its duration.
	 *
	 * @param string                    $jobClass The job class the run belongs to.
	 * @param callable                  $work     The execution to observe.
	 * @param string                    $cause    One of the JobRun CAUSE_ constants.
	 * @param string|null               $actor    The uid that caused the run, when a person did.
	 * @param mixed                     $argument The job argument, digested into the row.
	 * @param array<string, mixed>|null $details  What the act concerned.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @throws Throwable Whatever the work threw, after the failure is recorded.
	 */
	public function around(
		string $jobClass,
		callable $work,
		string $cause = JobRun::CAUSE_SCHEDULE,
		?string $actor = null,
		mixed $argument = null,
		?array $details = null,
	): mixed;
}//end interface
