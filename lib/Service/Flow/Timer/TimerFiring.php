<?php

/**
 * TimerFiring: what a business timer fired, as FlowTimerFiredEvent reports it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The kind of fire, the named transition it raised and, for a rung, the rung's key.
 *
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */
final class TimerFiring {

	/**
	 * Constructor.
	 *
	 * @param string      $kind       `rung` or `expiry` (FlowTimerFiredEvent::KIND_*).
	 * @param string      $transition The named transition raised.
	 * @param string|null $rungKey    The rung's stable key, for a rung fire.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $transition,
		public readonly ?string $rungKey = null,
	) {
	}//end __construct()
}//end class
