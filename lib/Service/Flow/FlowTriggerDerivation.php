<?php

/**
 * Derives a flow's trigger set from its trigger NODES.
 *
 * A flow's entry points are authored as nodes on the graph — that is the whole
 * point of the inversion: a trigger is a starting node, not a setting hanging
 * off the flow. But matching an event against every flow's node list would mean
 * decoding two JSON columns per flow on every object event, which is the one
 * place in this engine that must not scale with the number of flows.
 *
 * So the nodes are the AUTHORING surface and a derived, indexable set is the
 * MATCHING surface. This class is the derivation between them, and it is
 * deliberately the only place that knows the mapping — a second implementation
 * would be a second answer to "does this flow fire?", and the two would diverge
 * on exactly the flows nobody looks at.
 *
 * WHY THE FOUR COLUMNS CANNOT STAY
 * --------------------------------
 * `trigger`, `trigger_register` and `trigger_schema` hold exactly ONE trigger.
 * A flow with two trigger nodes is not representable in them at all — not
 * "awkward to represent", but structurally impossible: the second trigger has
 * nowhere to go. Any cutover therefore has to widen the storage, and this
 * derivation is what tells us whether doing so changes which flows fire.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;

/**
 * Turns trigger nodes into the set of (event, register, schema) a flow fires on.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */
class FlowTriggerDerivation {

	/**
	 * The node types that are entry points.
	 *
	 * Named here rather than discovered from the registry on purpose: this
	 * derivation runs against flows whose node-providing app may not be
	 * installed, and a trigger that stops being recognised because an app is
	 * missing would silently unsubscribe the flow from its own events.
	 *
	 * @var array<int, string>
	 */
	public const TRIGGER_TYPES = [
		'openregister.trigger-object',
		'openregister.trigger-schedule',
		'openregister.trigger-manual',
	];

	/**
	 * The one node type whose triggers are matched against object events.
	 *
	 * @var string
	 */
	public const OBJECT_TRIGGER = 'openregister.trigger-object';

	/**
	 * The trigger set a flow's nodes describe.
	 *
	 * Returns one entry per object trigger node, each naming exactly one event,
	 * register and schema — the shape `TriggerObjectNode` validates, so a
	 * derived entry is always fully qualified. Schedule and manual triggers are
	 * NOT object triggers and are excluded: they are matched by the scheduler
	 * and by an explicit run request, never by an object event.
	 *
	 * @param Flow $flow The flow to read.
	 *
	 * @return array<int, array{event: string, register: string, schema: string}> The trigger set.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function objectTriggersOf(Flow $flow): array {
		$derived = [];
		foreach ($this->triggerNodesOf(flow: $flow) as $node) {
			$trigger = $this->objectTriggerOf(node: $node);
			if ($trigger !== null) {
				$derived[implode('|', $trigger)] = $trigger;
			}
		}

		return array_values($derived);
	}//end objectTriggersOf()

	/**
	 * One trigger node as a fully qualified object trigger, or null.
	 *
	 * An incompletely configured trigger is skipped rather than widened.
	 * Treating a missing register as "any register" would subscribe a
	 * half-authored flow to every object event in the instance — the loudest
	 * possible failure mode for the quietest possible mistake.
	 *
	 * @param array<string, mixed> $node A trigger node.
	 *
	 * @return array{event: string, register: string, schema: string}|null The trigger, or null.
	 */
	private function objectTriggerOf(array $node): ?array {
		if ((string)($node['type'] ?? '') !== self::OBJECT_TRIGGER) {
			return null;
		}

		$trigger = $this->subjectOf(config: (array)($node['config'] ?? []));
		if (in_array('', $trigger, true) === true) {
			return null;
		}

		return $trigger;
	}//end objectTriggerOf()

	/**
	 * The trimmed event, register and schema a trigger config names.
	 *
	 * @param array<mixed> $config The trigger config.
	 *
	 * @return array{event: string, register: string, schema: string} The subject.
	 */
	private function subjectOf(array $config): array {
		$subject = [];
		foreach (['event', 'register', 'schema'] as $key) {
			$subject[$key] = trim((string)($config[$key] ?? ''));
		}

		return $subject;
	}//end subjectOf()

	/**
	 * The flow's trigger nodes, whatever their kind.
	 *
	 * @param Flow $flow The flow to read.
	 *
	 * @return array<int, array<string, mixed>> The trigger nodes.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function triggerNodesOf(Flow $flow): array {
		$nodes = $flow->getNodes();
		if (is_array($nodes) === false) {
			return [];
		}

		return array_values(array_filter($nodes, fn ($node): bool => $this->isTriggerNode(node: $node)));
	}//end triggerNodesOf()

	/**
	 * Whether one node list entry is a trigger node.
	 *
	 * @param mixed $node One entry from the node list.
	 *
	 * @return bool True for a trigger node.
	 */
	private function isTriggerNode(mixed $node): bool {
		return is_array($node) === true
			&& in_array(needle: (string)($node['type'] ?? ''), haystack: self::TRIGGER_TYPES, strict: true) === true;
	}//end isTriggerNode()

	/**
	 * The trigger the four legacy columns describe, in the derived shape.
	 *
	 * Empty `trigger_register`/`trigger_schema` mean ANY, which the node model
	 * cannot express — `TriggerObjectNode` requires both. That asymmetry is the
	 * whole substance of the cutover, so it is represented faithfully here as
	 * `'*'` rather than smoothed into a blank.
	 *
	 * @param Flow $flow The flow to read.
	 *
	 * @return array{event: string, register: string, schema: string}|null The
	 *                                                                     column trigger, or null when the flow has none.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function columnTriggerOf(Flow $flow): ?array {
		$event = trim((string)($flow->getTrigger() ?? ''));
		if ($event === '') {
			return null;
		}

		return [
			'event' => $event,
			'register' => $this->scopeOf(value: $flow->getTriggerRegister()),
			'schema' => $this->scopeOf(value: $flow->getTriggerSchema()),
		];
	}//end columnTriggerOf()

	/**
	 * One legacy scope column, with blank meaning ANY written as `*`.
	 *
	 * @param mixed $value The column value.
	 *
	 * @return string The scope.
	 */
	private function scopeOf(mixed $value): string {
		$scope = trim((string)($value ?? ''));
		if ($scope === '') {
			return '*';
		}

		return $scope;
	}//end scopeOf()

	/**
	 * Whether the nodes and the columns would fire on the same events.
	 *
	 * This is the question the cutover turns on, and it is asked per flow
	 * rather than in aggregate: "most flows agree" is not a safe basis for
	 * changing which flows run.
	 *
	 * A column trigger scoped to ANY register or ANY schema is reported as a
	 * DIVERGENCE even when a node names the same event, because the two do not
	 * match the same set of events — the column form fires on registers the
	 * node form does not.
	 *
	 * @param Flow $flow The flow to compare.
	 *
	 * @return array{equivalent: bool, reason: string, columns: array|null, nodes: array} The verdict.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function compare(Flow $flow): array {
		$sides = ['columns' => $this->columnTriggerOf(flow: $flow), 'nodes' => $this->objectTriggersOf(flow: $flow)];

		$decided = $this->verdictWithoutComparing(sides: $sides);
		if ($decided !== null) {
			return $decided;
		}

		if (in_array($sides['columns'], $sides['nodes'], true) === false) {
			return ['equivalent' => false, 'reason' => 'no node trigger matches the column trigger'] + $sides;
		}

		return ['equivalent' => true, 'reason' => $this->matchReason(nodes: $sides['nodes'])] + $sides;
	}//end compare()

	/**
	 * The verdicts reachable without comparing one trigger to another.
	 *
	 * Extracted from `compare()`, which sat exactly on the configured
	 * Cyclomatic Complexity threshold (10 of 10). The seam is real rather than
	 * arbitrary: every branch here is decided by what EXISTS on each side, and
	 * the caller keeps the one question that needs the two sides held together
	 * — does any node trigger equal the column trigger.
	 *
	 * THE ORDER OF THESE FOUR CHECKS IS LOAD-BEARING and is unchanged. The
	 * unscoped-column case must be judged before any equality test, because a
	 * `*` register or schema is not a value a node can hold: comparing it would
	 * report "no node matches" and read as a re-authoring gap, when the truth is
	 * that the column trigger cannot be expressed as a node at all.
	 *
	 * Returns the verdict, or null when the two sides must actually be compared.
	 *
	 * @param array{columns: array<string, string>|null, nodes: array<int, array<string, string>>} $sides The column and node triggers.
	 *
	 * @return array{equivalent: bool, reason: string, columns: array|null, nodes: array}|null
	 */
	private function verdictWithoutComparing(array $sides): ?array {
		$reason = $this->reasonWithoutComparing(columns: $sides['columns'], nodes: $sides['nodes']);
		if ($reason === null) {
			return null;
		}

		return ['equivalent' => ($sides['columns'] === null && $sides['nodes'] === []), 'reason' => $reason] + $sides;
	}//end verdictWithoutComparing()

	/**
	 * Why the two sides differ, or agree, without comparing them; null when they must be compared.
	 *
	 * Checked in the load-bearing order `verdictWithoutComparing()` documents.
	 *
	 * @param array{event: string, register: string, schema: string}|null       $columns The column trigger.
	 * @param array<int, array{event: string, register: string, schema: string}> $nodes   The node triggers.
	 *
	 * @return string|null The reason, or null.
	 */
	private function reasonWithoutComparing(?array $columns, array $nodes): ?string {
		if ($columns === null) {
			return $this->reasonWithoutColumns(nodes: $nodes);
		}

		if ($nodes === []) {
			return 'columns declare a trigger no node does: the flow WOULD STOP firing';
		}

		return $this->unscopedReason(columns: $columns);
	}//end reasonWithoutComparing()

	/**
	 * The reason when the columns declare no trigger.
	 *
	 * @param array<int, array{event: string, register: string, schema: string}> $nodes The node triggers.
	 *
	 * @return string The reason.
	 */
	private function reasonWithoutColumns(array $nodes): string {
		if ($nodes === []) {
			return 'no object trigger either way';
		}

		return 'nodes declare a trigger the columns do not: the flow does NOT fire today';
	}//end reasonWithoutColumns()

	/**
	 * The reason when the column trigger is unscoped, or null when it is scoped.
	 *
	 * @param array{event: string, register: string, schema: string} $columns The column trigger.
	 *
	 * @return string|null The reason, or null.
	 */
	private function unscopedReason(array $columns): ?string {
		if ($columns['register'] === '*' || $columns['schema'] === '*') {
			return 'the column trigger is unscoped (any register/schema), which no node can express';
		}

		return null;
	}//end unscopedReason()

	/**
	 * The reason when a node trigger equals the column trigger.
	 *
	 * @param array<int, array{event: string, register: string, schema: string}> $nodes The node triggers.
	 *
	 * @return string The reason.
	 */
	private function matchReason(array $nodes): string {
		if (count($nodes) > 1) {
			return 'the column trigger is among the node triggers; the extra node triggers are NEW';
		}

		return 'exact match';
	}//end matchReason()
}//end class
