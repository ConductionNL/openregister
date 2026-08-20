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

		$nodes = $this->extractNodes(flow: $flow);
		if (empty($nodes) === true) {
			throw new InvalidArgumentException(message: 'Flow declares no nodes; nothing to run.');
		}

		$edges = $this->extractEdges(flow: $flow, nodes: $nodes);
		$places = $this->buildPlaces(nodes: $nodes, edges: $edges);
		$transitions = $this->buildTransitions(nodes: $nodes, edges: $edges);
		$initial = $this->resolveInitialPlaces(flow: $flow, nodes: $nodes, edges: $edges);

		return new Definition(places: $places, transitions: $transitions, initialPlaces: $initial);
	}//end build()

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
			if (is_array($edge) === false) {
				continue;
			}

			if (trim((string)($edge['type'] ?? '')) === '') {
				continue;
			}

			throw new InvalidArgumentException(
				message: sprintf(
					'Flow edge "%s" carries "type", which the engine no longer reads — an edge is sequence and a '
					. 'NODE is the action. This flow is in the pre-inversion shape and has not been migrated; run '
					. 'the or-flow-migrate-definitions migration. It is refused rather than reinterpreted because '
					. 'a half-migrated flow would run, skip the step nobody claimed, and report success.',
					(string)($edge['id'] ?? $edge['name'] ?? $index)
				)
			);
		}//end foreach

	}//end refuseLegacyShape()

	/**
	 * Collect the declared action nodes, keyed by id.
	 *
	 * @param array $flow The flow document.
	 *
	 * @return array<string, array> Node id => node, in declaration order.
	 *
	 * @throws InvalidArgumentException When a node has no usable id, or ids collide.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function extractNodes(array $flow): array {
		$nodes = [];
		foreach (($flow['nodes'] ?? []) as $index => $node) {
			if (is_array($node) === false) {
				throw new InvalidArgumentException(
					message: sprintf('Flow node at index %d is not an object.', $index)
				);
			}

			$id = trim((string)($node['id'] ?? ''));
			if ($id === '') {
				throw new InvalidArgumentException(
					message: sprintf('Flow node at index %d has no id.', $index)
				);
			}

			// A duplicate id would silently merge two nodes into one transition,
			// so the flow would run but not be the flow the user drew.
			if (array_key_exists($id, $nodes) === true) {
				throw new InvalidArgumentException(
					message: sprintf('Flow declares node id "%s" more than once.', $id)
				);
			}

			// A node IS the step. One with no type resolves to nothing, runs,
			// and reports COMPLETED having done nothing — the exact silent
			// success this engine refuses to produce.
			if (trim((string)($node['type'] ?? '')) === '') {
				throw new InvalidArgumentException(
					message: sprintf(
						'Flow node "%s" declares no "type". A node is the action that runs, so a node without a '
						. 'type is a step that does nothing while reporting success.',
						$id
					)
				);
			}

			$this->assertExitsCanAlwaysBeTaken(node: $node, id: $id);

			$nodes[$id] = $node;
		}//end foreach

		return $nodes;
	}//end extractNodes()

	/**
	 * A branching node must always have somewhere to put its token.
	 *
	 * A token is unique and exclusive: a node with several exits takes exactly
	 * one per firing. If every exit is conditioned and none of them holds, the
	 * token has nowhere to go — and that does not raise anything. The run just
	 * stops, reporting no failure, which is indistinguishable from a flow that
	 * finished its work.
	 *
	 * So a node that conditions ANY exit must also declare an else: an exit with
	 * no condition. One is enough; a second would never be reachable, since the
	 * first unconditioned exit always matches.
	 *
	 * Refused at build time rather than warned about, for the same reason the
	 * engine refuses a step with no type — the failure it prevents is silent,
	 * and a silent stop in a scheduled flow is found weeks later by noticing
	 * work that never happened.
	 *
	 * @param array $node The node.
	 * @param string $id Its id, for the message.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a branching node has no else.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function assertExitsCanAlwaysBeTaken(array $node, string $id): void {
		$exits = ($node['exits'] ?? []);
		if (is_array($exits) === false || $exits === []) {
			// No declared exits at all: the node does not branch, so every
			// output place is taken and nothing can be stranded.
			return;
		}

		// Deliberately NOT gated on having two or more exits. A single
		// conditioned exit strands the token just as completely when its
		// condition does not hold — there is simply no sibling to notice.
		foreach ($exits as $exit) {
			if (is_array($exit) === false) {
				continue;
			}

			$condition = ($exit['condition'] ?? null);
			if (is_array($condition) === false || $condition === []) {
				// An unconditioned exit: the else. Nothing to refuse.
				return;
			}
		}

		throw new InvalidArgumentException(
			message: sprintf(
				'Flow node "%s" conditions all %d of its exits and declares no else. A token is exclusive and '
				. 'must always leave through exactly one exit, so if no condition holds the token has nowhere to '
				. 'go — and the run stops without reporting anything, which is indistinguishable from a flow that '
				. 'finished. Add an exit with no condition.',
				$id,
				count($exits)
			)
		);

	}//end assertExitsCanAlwaysBeTaken()

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
			$from = $this->graph()->normaliseEndpoints(value: ($edge['from'] ?? null));
			$to = $this->graph()->normaliseEndpoints(value: ($edge['to'] ?? null));

			if (empty($from) === true || empty($to) === true) {
				throw new InvalidArgumentException(
					message: sprintf('Flow edge at index %d must declare both "from" and "to".', $index)
				);
			}

			// Resolve every endpoint now. A dangling edge that reaches
			// symfony/workflow surfaces as an opaque failure at run time; here we
			// can name the edge and the missing node.
			foreach (array_merge($from, $to) as $endpoint) {
				if (array_key_exists($endpoint, $nodes) === false) {
					throw new InvalidArgumentException(
						message: sprintf(
							'Flow edge "%s" references unknown node "%s".',
							(string)($edge['id'] ?? $index),
							$endpoint
						)
					);
				}
			}

			$edges[] = [
				'id' => (string)($edge['id'] ?? sprintf('edge-%d', $index)),
				'from' => $from,
				'to' => $to,
			];
		}//end foreach

		return $edges;
	}//end extractEdges()

	/**
	 * Every place the net needs.
	 *
	 * @param array<string, array> $nodes The declared nodes.
	 * @param array $edges The normalised edges.
	 *
	 * @return array<string> The place names.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function buildPlaces(array $nodes, array $edges): array {
		$places = [];
		foreach (array_keys($nodes) as $id) {
			if ($this->graph()->isJoin(node: $nodes[$id]) === true) {
				// A join needs one input place PER incoming edge, so it can
				// require a token on each.
				foreach ($this->graph()->incoming(nodeId: $id, edges: $edges) as $edge) {
					$places[] = $this->graph()->joinPlace(nodeId: $id, edgeId: $edge['id']);
				}
			}

			// The shared input place exists for every node regardless: a join
			// with no incoming edges still needs somewhere to start.
			$places[] = $this->graph()->inPlace(nodeId: $id);

			if (empty($this->graph()->outgoing(nodeId: $id, edges: $edges)) === true) {
				$places[] = self::PLACE_END . $id;
			}
		}//end foreach

		return array_values(array_unique($places));
	}//end buildPlaces()

	/**
	 * One transition per action node, carrying that node's behaviour.
	 *
	 * @param array<string, array> $nodes The declared nodes.
	 * @param array $edges The normalised edges.
	 *
	 * @return array<Transition> The transitions.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function buildTransitions(array $nodes, array $edges): array {
		$transitions = [];
		foreach ($nodes as $id => $node) {
			$incoming = $this->graph()->incoming(nodeId: $id, edges: $edges);
			$outgoing = $this->graph()->outgoing(nodeId: $id, edges: $edges);

			$froms = [$this->graph()->inPlace(nodeId: $id)];
			if ($this->graph()->isJoin(node: $node) === true && empty($incoming) === false) {
				$froms = [];
				foreach ($incoming as $edge) {
					$froms[] = $this->graph()->joinPlace(nodeId: $id, edgeId: $edge['id']);
				}
			}

			$tos = [];
			foreach ($outgoing as $edge) {
				foreach ($edge['to'] as $target) {
					$tos[] = $this->graph()->targetPlace(nodeId: $target, edgeId: $edge['id'], nodes: $nodes);
				}
			}

			if (empty($tos) === true) {
				$tos = [self::PLACE_END . $id];
			}

			$transitions[] = new Transition(
				name: $id,
				froms: array_values(array_unique($froms)),
				tos: array_values(array_unique($tos))
			);
		}//end foreach

		return $transitions;
	}//end buildTransitions()

	/**
	 * The place an edge deposits its token on when it reaches `$nodeId`.
	 *
	 * A plain node shares one input place across every incoming edge, which is
	 * what makes converging edges a MERGE. A declared join takes one place per
	 * incoming edge instead, which is what makes it wait for all of them.
	 *
	 * @param string $nodeId The target node.
	 * @param string $edgeId The edge arriving at it.
	 * @param array<string, array> $nodes The declared nodes.
	 *
	 * @return string The place name.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */

	/**
	 * Decide which place(s) a run starts on.
	 *
	 * Explicit `initial` wins and names NODES. Otherwise the start is inferred
	 * as the nodes no edge points at — the flow's sources. Inference keeps
	 * simple flows free of boilerplate while still letting a user override it.
	 *
	 * @param array $flow The flow document.
	 * @param array<string, array> $nodes The declared nodes.
	 * @param array $edges The normalised edges.
	 *
	 * @return array<string> The initial place names.
	 *
	 * @throws InvalidArgumentException When `initial` names an unknown node.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function resolveInitialPlaces(array $flow, array $nodes, array $edges): array {
		$declared = $this->graph()->normaliseEndpoints(value: ($flow['initial'] ?? null));
		if (empty($declared) === false) {
			$initial = [];
			foreach ($declared as $id) {
				if (array_key_exists($id, $nodes) === false) {
					throw new InvalidArgumentException(
						message: sprintf('Flow initial node "%s" is not a declared node.', $id)
					);
				}

				$initial[] = $this->graph()->inPlace(nodeId: $id);
			}

			return $initial;
		}

		$sources = [];
		foreach (array_keys($nodes) as $id) {
			if (empty($this->graph()->incoming(nodeId: $id, edges: $edges)) === true) {
				$sources[] = $this->graph()->inPlace(nodeId: $id);
			}
		}

		// A fully cyclic flow has no source. Rather than refuse to run it, start
		// on the first declared node: the author drew a loop, which is
		// legitimate, and declaration order is the only signal available.
		if (empty($sources) === true) {
			return [$this->graph()->inPlace(nodeId: (string)array_key_first($nodes))];
		}

		return $sources;
	}//end resolveInitialPlaces()
}//end class
