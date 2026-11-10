<?php

/**
 * Who walks a run's streams, and how far one walk may go.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The pass's claim token and the limits of one stream walk.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamWalkLimits {
	/**
	 * Bundle the limits.
	 *
	 * @param string      $owner      This pass's claim token.
	 * @param int|null    $runCap     The per-run stream cap; null for FlowConcurrency's default.
	 * @param string|null $onlyStream Restrict the walk to one stream (an in-request advance).
	 * @param int|null    $budget     Firings this walk may commit; null for unbounded (the ceiling still applies).
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(
		public readonly string $owner,
		public readonly ?int $runCap = null,
		public readonly ?string $onlyStream = null,
		public readonly ?int $budget = null,
	) {
	}//end __construct()
}//end class
