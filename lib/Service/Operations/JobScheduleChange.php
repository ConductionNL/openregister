<?php

/**
 * JobScheduleChange: what an administrator changes about a job's schedule.
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
 * The administered parts of one job's schedule; null leaves a part as it is.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
 */
final readonly class JobScheduleChange {

	/**
	 * Describe the change.
	 *
	 * @param bool|null $enabled         Whether it may run at all.
	 * @param int|null  $intervalSeconds How often it is due.
	 * @param int|null  $windowStartHour The first hour it may run in, 0-23.
	 * @param int|null  $windowEndHour   The last hour it may run in, 0-23.
	 */
	public function __construct(
		public ?bool $enabled = null,
		public ?int $intervalSeconds = null,
		public ?int $windowStartHour = null,
		public ?int $windowEndHour = null,
	) {
	}//end __construct()
}//end class
