<?php

/**
 * TimerChange: why, by whom and when a timer changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTimeInterface;

/**
 * Why, by whom and when a timer is cancelled or re-anchored: the reason and actor
 * recorded on the timer's ledger, and the clock (now when null).
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
 */
final class TimerChange {

	/**
	 * Constructor
	 *
	 * @param string                 $reason Why, recorded on each timer.
	 * @param string|null            $actor  The acting identity or propagation source, recorded as actor.
	 * @param DateTimeInterface|null $now    The clock; the current time when null.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly ?string $actor = null,
		public readonly ?DateTimeInterface $now = null,
	) {
	}//end __construct()
}//end class
