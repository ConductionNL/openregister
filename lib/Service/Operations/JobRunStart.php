<?php

/**
 * OpenRegister job run start
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
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

namespace OCA\OpenRegister\Service\Operations;

/**
 * The job class a recorded run belongs to, and why and by whom it started.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
final class JobRunStart {
	/**
	 * Constructor.
	 *
	 * @param string        $jobClass The job class the run belongs to.
	 * @param JobRunContext $context  The cause, actor, argument and details; the default is a scheduled run.
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly JobRunContext $context = new JobRunContext(),
	) {
	}//end __construct()
}//end class
