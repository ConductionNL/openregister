<?php

/**
 * An enforcing business-timer outcome applied to a task, and the timer that applies it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The outcome a business timer applies (`skip`, `error`, `dead_letter` or
 * `transition:<action>`), and the trigger: the timer identity recorded as
 * actor and why, recorded on the audit.
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
 */
final class TimerOutcome {

	/**
	 * Constructor.
	 *
	 * @param string      $outcome `skip`, `error`, `dead_letter` or `transition:<action>`.
	 * @param TaskTrigger $trigger The timer identity recorded as actor (`flow-timer:<uuid>`), and why.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly TaskTrigger $trigger,
	) {
	}//end __construct()
}//end class
