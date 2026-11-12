<?php

/**
 * One step of a dry run: the node id, its configuration, the items it receives and the run context.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One step of a dry run, as {@see PreviewStepDispatcher} performs or describes it.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */
final class DryRunStep {
	/**
	 * Constructor.
	 *
	 * @param string $nodeId  The step's node id, the key its preview is kept under.
	 * @param array  $config  The step configuration.
	 * @param array  $items   The input items.
	 * @param array  $context Run-level metadata.
	 */
	public function __construct(
		public readonly string $nodeId,
		public readonly array $config,
		public readonly array $items,
		public readonly array $context,
	) {
	}//end __construct()
	/**
	 * What the step's node is called with.
	 *
	 * @return FlowNodeCall The items, the step configuration and the context.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function nodeCall(): FlowNodeCall {
		return new FlowNodeCall(items: $this->items, config: $this->config, context: $this->context);
	}//end nodeCall()
}//end class
