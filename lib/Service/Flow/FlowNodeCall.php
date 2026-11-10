<?php

/**
 * What a flow step is called with: its input items, its configuration and the run context.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One call of a node: {@see IFlowNode::execute()} and {@see IFlowNodePreview::preview()} take it.
 *
 * The items are the data channel ({@see FlowItems}); the context is run-level
 * metadata and never carries data between steps.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */
final class FlowNodeCall {
	/**
	 * Constructor.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step's authored configuration.
	 * @param array $context Run-level metadata.
	 */
	public function __construct(
		public readonly array $items=[],
		public readonly array $config=[],
		public readonly array $context=[],
	) {
	}//end __construct()

	/**
	 * The same call with other input items.
	 *
	 * @param array $items The input items.
	 *
	 * @return self The new call.
	 */
	public function withItems(array $items): self {
		return new self(items: $items, config: $this->config, context: $this->context);
	}//end withItems()

	/**
	 * The same call with another configuration.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return self The new call.
	 */
	public function withConfig(array $config): self {
		return new self(items: $this->items, config: $config, context: $this->context);
	}//end withConfig()

	/**
	 * The same call with another run context.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return self The new call.
	 */
	public function withContext(array $context): self {
		return new self(items: $this->items, config: $this->config, context: $context);
	}//end withContext()
}//end class
