<?php
/**
 * JobRunRecordingInterface: a background job's run recorded around its execution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Service\Operations\JobRunStart;
use Throwable;

/**
 * Records a job run, its outcome and its duration around the work.
 *
 * The recorded job base (Core), which jobs of every area extend, asks; the
 * operations run history (Configuration) answers and is bound to this
 * contract.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
interface JobRunRecordingInterface {

	/**
	 * Run the work and record the run, its outcome and its duration.
	 *
	 * @param JobRunStart $start The job class the run belongs to, with its cause, actor, argument and details.
	 * @param callable    $work  The execution to observe.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @throws Throwable Whatever the work threw, after the failure is recorded.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function around(JobRunStart $start, callable $work): mixed;
}//end interface
