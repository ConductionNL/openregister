<?php

/**
 * What a hop fires: the transition, its step and the items it receives.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The transition a walk chose, with the step behind it and the items on its input places.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
final class FlowHopPlan {
	/**
	 * Set up the plan.
	 *
	 * @param object            $transition The transition to fire.
	 * @param array             $step       The step definition behind it.
	 * @param array<int, mixed> $itemsIn    The items the step receives.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly object $transition,
		public readonly array $step,
		public readonly array $itemsIn,
	) {
	}//end __construct()
}//end class
