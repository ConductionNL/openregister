<?php

/**
 * JobRunFilter: the narrowing the operations console applies to the run log.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The job class, outcome and period one run-log read is narrowed to.
 *
 * A null or empty job class or outcome, and a null bound, leave that part of
 * the read unnarrowed.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
final class JobRunFilter {
	/**
	 * Constructor.
	 *
	 * @param string|null   $jobClass Narrow to one job class.
	 * @param string|null   $outcome  Narrow to one JobRun OUTCOME_ constant.
	 * @param DateTime|null $since    Lower bound on `started`.
	 * @param DateTime|null $until    Upper bound on `started`.
	 */
	public function __construct(
		public readonly ?string $jobClass = null,
		public readonly ?string $outcome = null,
		public readonly ?DateTime $since = null,
		public readonly ?DateTime $until = null,
	) {
	}//end __construct()
}//end class
