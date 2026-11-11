<?php

/**
 * The step dispatcher of a flow dry-run.
 *
 * It takes the place of {@see RegistryStepDispatcher} when a flow is tried
 * against an object. A step whose node declares {@see IFlowSideEffectFree} is
 * performed, so the items a later condition reads are shaped as in a run.
 * Every other step is described and NOT performed: a node that implements
 * {@see IFlowNodePreview} says what it would do, any other node is named as a
 * step that would run, and its input items pass on unchanged.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Performs side-effect-free steps and describes the rest.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class PreviewStepDispatcher implements FlowStepDispatcher {
	/**
	 * What each step would do, by node id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $previews = [];

	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistryInterface $registry The node registry.
	 */
	public function __construct(
		private readonly FlowNodeRegistryInterface $registry,
	) {
	}//end __construct()

	/**
	 * Perform a side-effect-free step, describe any other.
	 *
	 * @param array $step The step (the node) configuration.
	 * @param array $items The input items.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items: the node's for a performed step, the input otherwise.
	 *
	 * @throws \UnexpectedValueException When the step refuses its configuration.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dispatch(array $step, array $items, array $context): array {
		$type = trim((string)($step['type'] ?? ''));
		if ($type === '') {
			return $items;
		}

		$node = $this->registry->get(type: $type);

		return $this->previewStep(
			node: $node,
			step: new DryRunStep(nodeId: (string)($step['id'] ?? $type), config: (array)($step['config'] ?? []), items: $items, context: $context)
		);
	}//end dispatch()

	/**
	 * Perform a side-effect-free step and record it; validate and describe any other.
	 *
	 * @param IFlowNode  $node The step's node.
	 * @param DryRunStep $step The node id, configuration, input items and run context.
	 *
	 * @return array The output items: the node's for a performed step, the input otherwise.
	 *
	 * @throws \UnexpectedValueException When the step refuses its configuration.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function previewStep(IFlowNode $node, DryRunStep $step): array {
		if ($node instanceof IFlowSideEffectFree) {
			$this->previews[$step->nodeId] = ['performed' => true, 'summary' => $node->getDisplayName()];
			return $node->execute(call: $step->nodeCall());
		}

		// A described step is never executed, so its own check has to run here
		// for a configuration the real run refuses to fail in the dry run too.
		$node->validateConfig($step->config);
		$this->previews[$step->nodeId] = $this->describe(node: $node, step: $step);

		return $step->items;
	}//end previewStep()

	/**
	 * What a step that is not performed would do.
	 *
	 * @param IFlowNode  $node The step's node.
	 * @param DryRunStep $step The node id, configuration, input items and run context.
	 *
	 * @return array<string, mixed> The preview: `performed` false and the node's summary.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function describe(IFlowNode $node, DryRunStep $step): array {
		if ($node instanceof IFlowNodePreview) {
			return array_merge(['performed' => false], $node->preview(call: $step->nodeCall()));
		}

		return ['performed' => false, 'summary' => $node->getDisplayName() . ' would run'];
	}//end describe()

	/**
	 * What each step reached would do, by node id.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function previews(): array {
		return $this->previews;
	}//end previews()
}//end class
