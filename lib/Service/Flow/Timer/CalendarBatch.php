<?php

/**
 * One batch of a calendar recompute: which calendar changed, to which version,
 * the candidate timers, and what to do with a timer whose moment moved.
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
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use Closure;

/**
 * A batch of timers to re-project against one calendar version.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
final class CalendarBatch {
	/**
	 * Describe the batch.
	 *
	 * @param string   $slug      The calendar that changed.
	 * @param string   $version   Its object version.
	 * @param iterable $timers    The candidate timers.
	 * @param Closure  $supersede What to do with a timer whose moment moved: (FlowTimer):void.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $version,
		public readonly iterable $timers,
		public readonly Closure $supersede,
	) {
	}//end __construct()
}//end class
