<?php

/**
 * One step handed to the dispatcher: the step as the flow declares it, the
 * items it runs on and the context it runs in.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * A step on its way to its node.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final class StepDispatch {
	/**
	 * Describe the dispatch.
	 *
	 * @param array $step    The step configuration: its `type`, `id` and `config`.
	 * @param array $items   The input items.
	 * @param array $context Run-level metadata.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function __construct(
		public readonly array $step,
		public readonly array $items,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * The step's node type; '' for a pure-routing edge.
	 *
	 * @return string The type.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function type(): string {
		return trim((string)($this->step['type'] ?? ''));
	}//end type()

	/**
	 * The step's own configuration.
	 *
	 * @return array The configuration.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function config(): array {
		return (array)($this->step['config'] ?? []);
	}//end config()

	/**
	 * Where a checkpoint says the run is: the node type, or the routing edge.
	 *
	 * @return string The checkpoint label.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function where(): string {
		if ($this->type() === '') {
			return 'a routing edge';
		}

		return $this->type();
	}//end where()

	/**
	 * The same step in a context scoped to its node.
	 *
	 * @param array $context The node's own context.
	 *
	 * @return self The scoped dispatch.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function withContext(array $context): self {
		return new self(step: $this->step, items: $this->items, context: $context);
	}//end withContext()

	/**
	 * What the step's node is called with.
	 *
	 * @return FlowNodeCall The items, the step configuration and the context.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function nodeCall(): FlowNodeCall {
		return new FlowNodeCall(items: $this->items, config: $this->config(), context: $this->context);
	}//end nodeCall()
}//end class
