<?php

/**
 * The dispatcher every consumer gets for free.
 *
 * `FlowEngine` owns *when* a step runs and asks a `FlowStepDispatcher` to
 * perform it. Before this class each consuming app had to write that dispatcher
 * itself, which meant each app re-implemented "look at the step's type, decide
 * what to do" — and the moment an app has a type switch of its own it has
 * grown half an engine again. That is exactly how this fleet ended up with six
 * things called "flow".
 *
 * This dispatcher resolves the step's `type` out of the shared
 * {@see FlowNodeRegistry} and calls the node that owns it. An app contributes
 * node types and stops there: no dispatcher, no engine, no graph walking.
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
 * Dispatches each step to the registered node that owns its type.
 */
class RegistryStepDispatcher implements FlowStepDispatcher {
	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistry $registry The node catalogue.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
	) {

	}//end __construct()

	/**
	 * Resolve the step's type and run its node.
	 *
	 * A step with no `type` passes its items through untouched. That is not
	 * leniency about unknown types — an UNKNOWN type still throws, via the
	 * registry. It is the pure-routing edge: an author drawing an edge purely
	 * to shape the graph has not asked for work to happen on it.
	 *
	 * @param array $step The step configuration.
	 * @param array $items The input items.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function dispatch(array $step, array $items, array $context): array {
		$type = trim((string)($step['type'] ?? ''));
		if ($type === '') {
			return $items;
		}

		$node = $this->registry->get(type: $type);

		return $node->execute(
			items: $items,
			config: (array)($step['config'] ?? []),
			context: $context
		);

	}//end dispatch()
}//end class
