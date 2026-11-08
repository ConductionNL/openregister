<?php

/**
 * The calendar, the business-time calculator and the escalation ladder of the timer lifecycle.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * The collaborators of FlowTimerService that compute a deadline, wired by DI autowiring.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
 */
final class FlowTimerArithmetic {
	/**
	 * Bundle the services.
	 *
	 * @param WorkingCalendarService  $calendars  Calendar resolution.
	 * @param SlaCalculator           $calculator Business-time arithmetic.
	 * @param EscalationLadderService $ladder     Rung resolution and validation.
	 */
	public function __construct(
		public readonly WorkingCalendarService $calendars,
		public readonly SlaCalculator $calculator,
		public readonly EscalationLadderService $ladder,
	) {
	}//end __construct()
}//end class
