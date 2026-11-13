<?php

/**
 * The resolved sub-flow a SubFlowNode invocation runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The sub-flow's document, its id and the recursion stack its child runs inherit.
 *
 * These three always travel together from the moment the sub-flow is
 * resolved: every child run of one step gets the same document, id and stack.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final class SubFlowTarget {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $flow   The resolved sub-flow document.
	 * @param string               $flowId The sub-flow id.
	 * @param array<int, string>   $stack  The recursion stack to hand each child (this id pushed).
	 */
	public function __construct(
		public readonly array $flow,
		public readonly string $flowId,
		public readonly array $stack,
	) {

	}//end __construct()
}//end class
