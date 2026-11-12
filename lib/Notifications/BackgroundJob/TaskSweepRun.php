<?php

/**
 * One sweep of one scheduled task rule: the rule, its clock instant and its tally.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use DateTime;
use DateTimeImmutable;

/**
 * The state of one rule's sweep inside TaskScheduledNotificationJob (ADR-011 Rule 3).
 *
 * Built by the job for one rule and never handed outside it. The sweep's one
 * clock instant travels in both shapes the collaborators need, so the
 * candidate query and the declared filter read the same "now"; the two
 * counters are the run's progress.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class TaskSweepRun {

	/**
	 * The same instant as `$now`, immutable, for the filter evaluator.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $nowDt;

	/**
	 * Tasks that matched the rule's filter so far.
	 *
	 * @var integer
	 */
	public int $matched = 0;

	/**
	 * Tasks dispatched so far.
	 *
	 * @var integer
	 */
	public int $dispatched = 0;

	/**
	 * Constructor.
	 *
	 * @param TaskSweep $sweep The rule being swept.
	 * @param DateTime  $now   The sweep's clock instant.
	 */
	public function __construct(
		public readonly TaskSweep $sweep,
		public readonly DateTime $now,
	) {
		$this->nowDt = DateTimeImmutable::createFromMutable($now);
	}//end __construct()
}//end class
