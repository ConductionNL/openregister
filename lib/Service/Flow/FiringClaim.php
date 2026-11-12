<?php

/**
 * The places one firing asks to claim: which run, which stream, which
 * transition, and `froms ∪ tos`.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One firing's claim on the places it touches.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */
final class FiringClaim {
	/**
	 * Describe the claim.
	 *
	 * @param string                 $runUuid    The run.
	 * @param string                 $streamId   The stream the firing belongs to.
	 * @param string                 $transition The transition name.
	 * @param array<int, string|int> $places     `froms ∪ tos`, in any order.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function __construct(
		public readonly string $runUuid,
		public readonly string $streamId,
		public readonly string $transition,
		public readonly array $places,
	) {
	}//end __construct()
}//end class
