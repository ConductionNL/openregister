<?php

/**
 * FlowTriggerFilter: whether a flow's object trigger accepts one firing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;

/**
 * The second, in-memory check after the trigger index.
 *
 * The index matches a firing on event, register and schema only. A trigger
 * node can narrow further: a `transition` filter on `object.transitioned`
 * (action, from, to) and a `changedFields` list on `object.updated`. This
 * answers whether ANY of the flow's object triggers for the event accepts the
 * firing's context.
 *
 * A flow with no object trigger for the event — a flow still on the legacy
 * trigger column — is accepted, exactly as before this filter existed.
 *
 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
 */
final class FlowTriggerFilter {

	/**
	 * Whether the flow should start for this firing.
	 *
	 * @param array<string, mixed>|null $graph   The flow's published graph, or null when unknown.
	 * @param string                    $event   The event id that fired.
	 * @param array<string, mixed>      $context The firing's context.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/flow-trigger-transitions-and-changed-fields/specs/flow-engine/spec.md
	 */
	public function accepts(?array $graph, string $event, array $context): bool {
		$triggers = $this->objectTriggersFor(graph: ($graph ?? []), event: $event);
		if ($triggers === []) {
			return true;
		}

		foreach ($triggers as $config) {
			if ($this->triggerAccepts(config: $config, context: $context) === true) {
				return true;
			}
		}

		return false;
	}//end accepts()

	/**
	 * The configs of the graph's object triggers that name this event.
	 *
	 * @param array<string, mixed> $graph The graph.
	 * @param string               $event The event id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objectTriggersFor(array $graph, string $event): array {
		$configs = [];
		foreach ((array)($graph['nodes'] ?? []) as $node) {
			if (is_array($node) === false || (string)($node['type'] ?? '') !== FlowTriggerDerivation::OBJECT_TRIGGER) {
				continue;
			}

			$config = (array)($node['config'] ?? []);
			if (trim((string)($config['event'] ?? '')) === $event) {
				$configs[] = $config;
			}
		}

		return $configs;
	}//end objectTriggersFor()

	/**
	 * Whether one trigger's filters accept the context.
	 *
	 * @param array<string, mixed> $config  The trigger config.
	 * @param array<string, mixed> $context The firing's context.
	 *
	 * @return bool
	 */
	private function triggerAccepts(array $config, array $context): bool {
		if (is_array($config['transition'] ?? null) === true
			&& $this->transitionAccepts(filter: $config['transition'], context: $context) === false
		) {
			return false;
		}

		if (is_array($config['changedFields'] ?? null) === true) {
			return $this->changedFieldsAccept(watched: $config['changedFields'], context: $context);
		}

		return true;
	}//end triggerAccepts()

	/**
	 * Every list the filter holds contains the transition's own value.
	 *
	 * @param array<string, mixed> $filter  The `transition` filter.
	 * @param array<string, mixed> $context The firing's context: `action`, `from`, `to`.
	 *
	 * @return bool
	 */
	private function transitionAccepts(array $filter, array $context): bool {
		$values = ['actions' => 'action', 'from' => 'from', 'to' => 'to'];
		foreach (TriggerObjectNode::TRANSITION_LISTS as $list) {
			if (array_key_exists($list, $filter) === false) {
				continue;
			}

			$value = (string)($context[$values[$list]] ?? '');
			if (in_array($value, (array)$filter[$list], true) === false) {
				return false;
			}
		}

		return true;
	}//end transitionAccepts()

	/**
	 * At least one watched field is among the fields that changed.
	 *
	 * No `changedFields` on the context (an update without its old version)
	 * proves nothing changed, so a watching trigger does not start.
	 *
	 * @param array<int, mixed>    $watched The watched property names.
	 * @param array<string, mixed> $context The firing's context.
	 *
	 * @return bool
	 */
	private function changedFieldsAccept(array $watched, array $context): bool {
		$changed = $context['changedFields'] ?? [];
		if (is_array($changed) === false) {
			return false;
		}

		return array_intersect(array_map('strval', $watched), array_map('strval', $changed)) !== [];
	}//end changedFieldsAccept()
}//end class
