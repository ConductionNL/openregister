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
	 * @param FlowNodeRegistry $registry The node registry.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
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
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	public function dispatch(array $step, array $items, array $context): array {
		$type = trim((string)($step['type'] ?? ''));
		if ($type === '') {
			return $items;
		}

		$nodeId = (string)($step['id'] ?? $type);
		$node = $this->registry->get(type: $type);
		$config = (array)($step['config'] ?? []);

		if ($node instanceof IFlowSideEffectFree) {
			$this->previews[$nodeId] = ['performed' => true, 'summary' => $node->getDisplayName()];
			return $node->execute(items: $items, config: $config, context: $context);
		}

		if ($node instanceof IFlowNodePreview) {
			$this->previews[$nodeId] = array_merge(
				['performed' => false],
				$node->preview(items: $items, config: $config, context: $context)
			);
			return $items;
		}

		$this->previews[$nodeId] = ['performed' => false, 'summary' => $node->getDisplayName() . ' would run'];

		return $items;
	}//end dispatch()

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
