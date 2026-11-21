<?php

/**
 * One invocation of a flow node: the items it receives, the step's
 * configuration and the run-level metadata, as `IFlowNode::execute()` hands
 * them over.
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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * The three inputs of one node execution, carried together through a node's helpers.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class NodeInvocation {
	/**
	 * Describe the invocation.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly array $items,
		public readonly array $config,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
