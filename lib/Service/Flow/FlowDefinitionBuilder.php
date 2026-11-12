<?php

/**
 * Lowers a stored OpenRegister flow document into a symfony/workflow Definition.
 *
 * This is the translation layer between what users author (actions connected by
 * sequence, drawn on a canvas) and what executes (a Petri net). It is the only
 * place that knows both shapes, so the engine never parses user JSON and the
 * stored document never mentions Symfony.
 *
 * ## What an author writes
 *
 *   node = an ACTION. Carries `type` and `config`. This is what runs.
 *   edge = SEQUENCE.  Carries `from`/`to`, and optionally a display title.
 *
 * That is the model every flow tool uses, because it is how a diagram reads: a
 * box is a thing that happens, an arrow is what happens next.
 *
 * ## What executes
 *
 * A Petri net (ADR-065): a superset of the two flow models this fleet already
 * has. A single-token marking is a state machine (procest: `case.status` is the
 * marking); a multi-token marking expresses parallel splits and synchronising
 * joins, which no existing fleet engine can do — openconnector's `order`-indexed
 * step list explicitly cannot, and that limit is why a canvas could not ship
 * against it.
 *
 * The Petri net is an INTERNAL representation. It used to be the authoring
 * format too — a node was a place and the step rode on the edge — and that is
 * the design this change reverses. See `or-flow-action-nodes`.
 *
 * ## The lowering
 *
 *   node N            -> transition T_N (carrying N's type/config) + place in(N)
 *   edge A -> B       -> in(B) added to T_A's targets
 *   node with no out  -> terminal place end(N)
 *   node with no in   -> in(N) joins the initial places
 *   node with join    -> one input place per incoming edge, all required
 *
 * Every node yields exactly one transition and every edge exactly one place
 * reference, so the construction is total: no document shape lowers to nothing.
 *
 * ## Merge is the default; join is opt-in
 *
 * Two edges arriving at one node could mean "run when EITHER finishes" (a merge)
 * or "wait for BOTH" (a join). Petri nets express these differently, and the
 * choice is not cosmetic: the Hydra sequencer reaches its exit from several
 * mutually-exclusive paths, so lowering converging edges to a join would require
 * all of them to fire and the flow would deadlock on every run — while still
 * producing a valid definition. So `in(N)` is SHARED across incoming edges, and
 * a synchronising join must say `join: true`.
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
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use InvalidArgumentException;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;

/**
 * Lowers a stored flow document into an executable Petri-net definition.
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */
class FlowDefinitionBuilder {
	/**
	 * Prefix for the terminal place of a node with no outgoing edge.
	 *
	 * @var string
	 */
	private const PLACE_END = 'end:';

	/**
	 * The graph-shape helper.
	 *
	 * Defaulted rather than required: the builder is constructed by hand in
	 * several unit tests and by consumers that pass no arguments at all, and
	 * the helper is stateless, so a locally-made one is the same object.
	 *
	 * @var FlowGraph|null
	 */
	private ?FlowGraph $graphHelper = null;

	/**
	 * The graph helper, made on demand.
	 *
	 * @return FlowGraph The helper.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function graph(): FlowGraph {
		if ($this->graphHelper === null) {
			$this->graphHelper = new FlowGraph();
		}

		return $this->graphHelper;
	}//end graph()

	/**
	 * Lower an action's inline `steps` into a flow document.
	 *
	 * A straight line: a manual trigger, the action-inputs step that makes
	 * `{{inputs.x}}` readable, the steps in declared order, and an end node
	 * carrying `next`. The result is an ordinary flow document, so it runs on
	 * the one engine and leaves an ordinary run behind (ADR-065).
	 *
	 * @param list<array<string, mixed>> $steps The declared steps: `{id?, type, config?, label?}`.
	 * @param string|null                $next  The action's `next` hint.
	 *
	 * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, string>>} The flow document.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-inline-steps-are-a-straight-line-lowered-to-the-one-flow-engine
	 */
	public function lowerSteps(array $steps, ?string $next = null): array {
		$nodes = [
			['id' => 'trigger', 'type' => FlowNextHint::MANUAL_TRIGGER, 'config' => []],
			['id' => 'inputs', 'type' => 'openregister.action-inputs', 'config' => []],
		];
		foreach (array_values($steps) as $index => $step) {
			$nodes[] = self::stepNode(step: $step, index: $index);
		}

		$nodes[] = [
			'id' => 'end',
			'type' => FlowNextHint::END_NODE,
			'config' => array_filter(['next' => $next], static fn (?string $hint): bool => $hint !== null),
		];

		return ['nodes' => $nodes, 'edges' => self::straightEdges(nodes: $nodes)];
	}//end lowerSteps()

	/**
	 * Build a Definition from a stored flow document.
	 *
	 * The document is user data and is therefore treated as hostile: every
	 * reference is resolved against the declared nodes before it reaches
	 * symfony/workflow, which would otherwise fail later and less legibly.
	 *
	 * @param array $flow The flow document: `{nodes: [{id, type, config}], edges: [{id, from, to}], initial?}`.
	 *
	 * @return Definition The executable definition.
	 *
	 * @throws InvalidArgumentException When the document does not describe a runnable flow.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function build(array $flow): Definition {
		$this->refuseLegacyShape(flow: $flow);

		$nodes = (new FlowNodeDeclarations())->extract(flow: $flow);
		if (empty($nodes) === true) {
			throw new InvalidArgumentException(message: 'Flow declares no nodes; nothing to run.');
		}

		$net = new LoweredFlow(nodes: $nodes, edges: $this->extractEdges(flow: $flow, nodes: $nodes));
		$places = $this->buildPlaces(net: $net);
		$transitions = $this->buildTransitions(net: $net);
		$initial = $this->declaredInitialPlaces(declared: ($flow['initial'] ?? null), nodes: $nodes);
		if ($initial === []) {
			$initial = $this->inferredInitialPlaces(net: $net);
		}

		return new Definition(places: $places, transitions: $transitions, initialPlaces: $initial);
	}//end build()

	/**
	 * One declared step as a flow node.
	 *
	 * @param mixed $step  The declared step: `{id?, type, config?, label?}`.
	 * @param int   $index Its position.
	 *
	 * @return array<string, mixed> The node.
	 */
	private static function stepNode(mixed $step, int $index): array {
		return array_merge(
			[
				'id' => 'step-' . ($index + 1) . '-' . (string)($step['id'] ?? ''),
				'type' => (string)($step['type'] ?? ''),
				'config' => (array)($step['config'] ?? []),
			],
			array_intersect_key($step, ['label' => true])
		);
	}//end stepNode()

	/**
	 * The edges of a straight line through the nodes, in order.
	 *
	 * @param list<array<string, mixed>> $nodes The nodes.
	 *
	 * @return list<array<string, string>> The edges.
	 */
	private static function straightEdges(array $nodes): array {
		return array_map(
			static fn (array $from, array $to): array => ['id' => $from['id'] . '>' . $to['id'], 'from' => $from['id'], 'to' => $to['id']],
			array_slice($nodes, 0, -1),
			array_slice($nodes, 1)
		);
	}//end straightEdges()

	/**
	 * Refuse a document authored in the pre-inversion shape.
	 *
	 * The predicate is deliberately narrow and total: a document is
	 * pre-inversion iff any EDGE carries a non-empty `type`. It cannot be true
	 * of a correctly migrated document, it never has to inspect node shape to
	 * decide, and a document carrying behaviour on both nodes and edges matches
	 * it — which is the safe direction.
	 *
	 * This replaces the old refusal of `nodes[].type`, which existed because a
	 * step on a node was a step nothing executed: dispatch returned items
	 * untouched, the run reported COMPLETED, and the trace was empty. That
	 * failure mode has not gone away, it has swapped sides — a step left on an
	 * EDGE is now the one nothing executes — so the refusal swaps with it.
	 * Reinterpreting instead of refusing would turn a loud migration into a
	 * silent, data-dependent one.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When an edge carries behaviour.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function refuseLegacyShape(array $flow): void {
		foreach (($flow['edges'] ?? []) as $index => $edge) {
			if (is_array($edge) === true && trim((string)($edge['type'] ?? '')) !== '') {
				throw new InvalidArgumentException(
					message: sprintf(
						'Flow edge "%s" carries "type", which the engine no longer reads — an edge is sequence and a '
						. 'NODE is the action. This flow is in the pre-inversion shape and has not been migrated; run '
						. 'the or-flow-migrate-definitions migration. It is refused rather than reinterpreted because '
						. 'a half-migrated flow would run, skip the step nobody claimed, and report success.',
						(string)($edge['id'] ?? $edge['name'] ?? $index)
					)
				);
			}
		}
	}//end refuseLegacyShape()

	/**
	 * Normalise the edges and resolve every endpoint against the declared nodes.
	 *
	 * @param array $flow The flow document.
	 * @param array<string, array> $nodes The declared nodes.
	 *
	 * @return array<int, array{id: string, from: array<string>, to: array<string>}> The edges.
	 *
	 * @throws InvalidArgumentException When an edge is malformed or dangling.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function extractEdges(array $flow, array $nodes): array {
		$edges = [];
		foreach (($flow['edges'] ?? []) as $index => $edge) {
			[$from, $to] = $this->endpoints(edge: $edge, index: $index);

			// Resolve every endpoint now. A dangling edge that reaches
			// symfony/workflow surfaces as an opaque failure at run time; here we
			// can name the edge and the missing node.
			$unknown = self::firstUnknown(endpoints: array_merge($from, $to), nodes: $nodes);
			if ($unknown !== null) {
				throw new InvalidArgumentException(
					message: sprintf('Flow edge "%s" references unknown node "%s".', (string)($edge['id'] ?? $index), $unknown)
				);
			}

			$edges[] = ['id' => (string)($edge['id'] ?? sprintf('edge-%d', $index)), 'from' => $from, 'to' => $to];
		}

		return $edges;
	}//end extractEdges()

	/**
	 * An edge's `from` and `to` node lists.
	 *
	 * @param mixed      $edge  The declared edge.
	 * @param int|string $index Its position, for the message.
	 *
	 * @return array{0: array<string>, 1: array<string>} The from and to ids.
	 *
	 * @throws InvalidArgumentException When either side is empty.
	 */
	private function endpoints(mixed $edge, int|string $index): array {
		$from = $this->graph()->normaliseEndpoints(value: ($edge['from'] ?? null));
		$to = $this->graph()->normaliseEndpoints(value: ($edge['to'] ?? null));
		if (empty($from) === true || empty($to) === true) {
			throw new InvalidArgumentException(
				message: sprintf('Flow edge at index %d must declare both "from" and "to".', $index)
			);
		}

		return [$from, $to];
	}//end endpoints()

	/**
	 * The first endpoint that names no declared node.
	 *
	 * @param array<string>        $endpoints The endpoint ids, in order.
	 * @param array<string, array> $nodes     The declared nodes.
	 *
	 * @return string|null The unknown id, or null when all resolve.
	 */
	private static function firstUnknown(array $endpoints, array $nodes): ?string {
		foreach ($endpoints as $endpoint) {
			if (array_key_exists($endpoint, $nodes) === false) {
				return $endpoint;
			}
		}

		return null;
	}//end firstUnknown()

	/**
	 * Every place the net needs.
	 *
	 * @param LoweredFlow $net The nodes and edges.
	 *
	 * @return array<string> The place names.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function buildPlaces(LoweredFlow $net): array {
		$places = [];
		foreach (array_keys($net->nodes) as $id) {
			$places = array_merge($places, $this->placesOf(net: $net, id: $id));
		}

		return array_values(array_unique($places));
	}//end buildPlaces()

	/**
	 * The places one node needs.
	 *
	 * A join needs one input place PER incoming edge, so it can require a
	 * token on each. The shared input place exists for every node regardless:
	 * a join with no incoming edges still needs somewhere to start.
	 *
	 * @param LoweredFlow $net The nodes and edges.
	 * @param int|string  $id  The node id, as the node map keys it.
	 *
	 * @return array<string> The place names.
	 */
	private function placesOf(LoweredFlow $net, int|string $id): array {
		$places = $this->joinPlaces(net: $net, id: $id);
		$places[] = $this->graph()->inPlace(nodeId: $id);
		if (empty($this->graph()->outgoing(nodeId: $id, edges: $net->edges)) === true) {
			$places[] = self::PLACE_END . $id;
		}

		return $places;
	}//end placesOf()

	/**
	 * A join node's per-edge input places; none for a node that is not a join.
	 *
	 * @param LoweredFlow $net The nodes and edges.
	 * @param int|string  $id  The node id, as the node map keys it.
	 *
	 * @return array<string> The place names.
	 */
	private function joinPlaces(LoweredFlow $net, int|string $id): array {
		if ($this->graph()->isJoin(node: $net->nodes[$id]) === false) {
			return [];
		}

		// The incoming() call takes a string id, so once it has answered the id is one.
		$incoming = $this->graph()->incoming(nodeId: $id, edges: $net->edges);
		$nodeId = (string)$id;

		return array_map(
			fn (array $edge): string => $this->graph()->joinPlace(nodeId: $nodeId, edgeId: $edge['id']),
			$incoming
		);
	}//end joinPlaces()

	/**
	 * One transition per action node, carrying that node's behaviour.
	 *
	 * @param LoweredFlow $net The nodes and edges.
	 *
	 * @return array<Transition> The transitions.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function buildTransitions(LoweredFlow $net): array {
		$transitions = [];
		foreach (array_keys($net->nodes) as $id) {
			$froms = $this->joinPlaces(net: $net, id: $id);
			if ($froms === []) {
				$froms = [$this->graph()->inPlace(nodeId: $id)];
			}

			$transitions[] = new Transition(
				name: $id,
				froms: array_values(array_unique($froms)),
				tos: array_values(array_unique($this->targetPlaces(net: $net, id: $id)))
			);
		}

		return $transitions;
	}//end buildTransitions()

	/**
	 * The places a node's transition deposits tokens on: its targets, or its end place.
	 *
	 * @param LoweredFlow $net The nodes and edges.
	 * @param int|string  $id  The node id, as the node map keys it.
	 *
	 * @return array<string> The place names.
	 */
	private function targetPlaces(LoweredFlow $net, int|string $id): array {
		$tos = [];
		foreach ($this->graph()->outgoing(nodeId: $id, edges: $net->edges) as $edge) {
			foreach ($edge['to'] as $target) {
				$tos[] = $this->graph()->targetPlace(arrival: new EdgeArrival(nodeId: $target, edgeId: $edge['id']), nodes: $net->nodes);
			}
		}

		if (empty($tos) === true) {
			$tos = [self::PLACE_END . $id];
		}

		return $tos;
	}//end targetPlaces()

	/**
	 * The places named by an explicit `initial`, which names NODES and wins when present.
	 *
	 * @param mixed                $declared The declared `initial`.
	 * @param array<string, array> $nodes    The declared nodes.
	 *
	 * @return array<string> The initial place names; empty when none are declared.
	 *
	 * @throws InvalidArgumentException When `initial` names an unknown node.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function declaredInitialPlaces(mixed $declared, array $nodes): array {
		$initial = [];
		foreach ($this->graph()->normaliseEndpoints(value: $declared) as $id) {
			if (array_key_exists($id, $nodes) === false) {
				throw new InvalidArgumentException(message: sprintf('Flow initial node "%s" is not a declared node.', $id));
			}

			$initial[] = $this->graph()->inPlace(nodeId: $id);
		}

		return $initial;
	}//end declaredInitialPlaces()

	/**
	 * The inferred start: the nodes no edge points at — the flow's sources.
	 *
	 * Inference keeps simple flows free of boilerplate while still letting a
	 * user override it. A fully cyclic flow has no source. Rather than refuse
	 * to run it, start on the first declared node: the author drew a loop,
	 * which is legitimate, and declaration order is the only signal available.
	 *
	 * @param LoweredFlow $net The nodes and edges.
	 *
	 * @return array<string> The initial place names.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function inferredInitialPlaces(LoweredFlow $net): array {
		$sources = [];
		foreach (array_keys($net->nodes) as $id) {
			if (empty($this->graph()->incoming(nodeId: $id, edges: $net->edges)) === true) {
				$sources[] = $this->graph()->inPlace(nodeId: $id);
			}
		}

		if (empty($sources) === true) {
			return [$this->graph()->inPlace(nodeId: (string)array_key_first($net->nodes))];
		}

		return $sources;
	}//end inferredInitialPlaces()
}//end class
