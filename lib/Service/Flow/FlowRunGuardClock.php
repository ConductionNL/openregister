<?php

/**
 * The clock one FlowRunGuard keeps for its run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Which run is guarded, when its walk began, how long it may take and how
 * often its liveness is written.
 *
 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
 */
final class FlowRunGuardClock {
	/**
	 * Bundle the clock.
	 *
	 * @param string  $runUuid     The run being guarded.
	 * @param float   $startedAt   microtime(true) when the walk began.
	 * @param integer $budget      Seconds the whole run may take; 0 disables.
	 * @param integer $minInterval Seconds between liveness writes.
	 *
	 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
	 */
	public function __construct(
		public readonly string $runUuid,
		public readonly float $startedAt,
		public readonly int $budget,
		public readonly int $minInterval = 30,
	) {
	}//end __construct()
}//end class
