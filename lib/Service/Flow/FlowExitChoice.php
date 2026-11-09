<?php

/**
 * FlowExitChoice: what one firing decides its exit on.
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
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The node's exits, the expression data of the firing and the outputs its items
 * are routed to. Built by FlowTokenRouter for one routing decision and never leaves it.
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */
final class FlowExitChoice {

	/**
	 * Constructor.
	 *
	 * @param FlowNodeExits        $exits The firing node within its flow.
	 * @param array<string, mixed> $data  The expression data exit conditions are evaluated against.
	 * @param array<string, bool>  $tags  The outputs the items are routed to, as keys.
	 */
	public function __construct(
		public readonly FlowNodeExits $exits,
		public readonly array $data,
		public readonly array $tags,
	) {
	}//end __construct()
}//end class
