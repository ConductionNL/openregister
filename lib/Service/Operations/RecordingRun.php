<?php

/**
 * RecordingRun: one execution the JobRunRecorder is recording.
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

use OCA\OpenRegister\Db\JobRun;

/**
 * The job class, the open row and the start moment of one recorded execution.
 *
 * ADR-011 Rule 3 run object: built by JobRunRecorder::around() and handed to
 * its helpers; it never leaves the recorder.
 *
 * @internal
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
final class RecordingRun {

	/**
	 * Describe the execution.
	 *
	 * @param string      $jobClass  The job class the run belongs to.
	 * @param JobRun|null $row       The open row, or null when the log was unwritable.
	 * @param float       $startedAt The microtime the work began.
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly ?JobRun $row,
		public readonly float $startedAt,
	) {
	}//end __construct()
}//end class
