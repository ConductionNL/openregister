<?php

/**
 * The collaborators of RecomputeTimersForCalendarJob, wired by DI autowiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\FlowTimerControlInterface;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecomputeInterface;

/**
 * Where the timers are, the supersession path and the recompute rule.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
final class RecomputeTimersForCalendarJobServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowTimerMapperInterface   $timers    Where the timers are.
	 * @param FlowTimerControlInterface  $service   The supersession path, reused whole.
	 * @param CalendarRecomputeInterface $recompute The rule.
	 */
	public function __construct(
		public readonly FlowTimerMapperInterface $timers,
		public readonly FlowTimerControlInterface $service,
		public readonly CalendarRecomputeInterface $recompute,
	) {
	}//end __construct()
}//end class
