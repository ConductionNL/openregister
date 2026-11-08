<?php

/**
 * JobRunFilter: which runs the run history lists.
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

/**
 * The job, the outcome and the period the run history is narrowed to.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
final readonly class JobRunFilter {

	/**
	 * Describe the filter.
	 *
	 * @param string|null $jobClass    Narrow to one job class.
	 * @param string|null $outcome     Narrow to one outcome.
	 * @param int|null    $windowHours How far back to look, null for all of it.
	 */
	public function __construct(
		public ?string $jobClass = null,
		public ?string $outcome = null,
		public ?int $windowHours = null,
	) {
	}//end __construct()
}//end class
