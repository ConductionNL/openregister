<?php

/**
 * Token routing for the OpenRegister flow engine.
 *
 * Split out of `FlowEngine`, which had grown past the class-length and
 * class-complexity thresholds once action nodes landed. The seam is the one the
 * code already had: every method here answers "where does the token go, and
 * which items go with it", and none of them touches the run lifecycle, the
 * trace, the error policy or any injected service — the group called nothing
 * but itself and `stepFor()`.
 *
 * Keeping it a collaborator rather than a bag of statics leaves it mockable in
 * an engine test and lets a future dialect override routing without reopening
 * the engine.
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

/**
 * Decides which exit a token takes, and which items travel with it.
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */
class FlowTokenRouter {
	/**
	 * Reads edge endpoints the one way the document defines them.
	 *
	 * ⚠️ THIS CLASS READ `to` AS A LIST AND `from` AS A SCALAR, IN THE SAME
	 * METHOD. `placesForExit()` unwrapped `to` by hand and then matched `from`
	 * with `(string)($edge['from'] ?? '')` — and `(string)["a"]` is `"Array"`,
	 * so no edge ever matched its source node and a token that fired had
	 * nowhere to go. Every endpoint now goes through the one normaliser, so
	 * the two ends of an edge cannot drift apart again.
	 *
	 * @var FlowGraph
	 */
	private FlowGraph $graph;

	/**
	 * Constructor.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function __construct() {
		$this->graph = new FlowGraph();

	}//end __construct()

	/**
	 * Find the step configuration attached to a transition.
	 *
	 * A transition IS a node: `FlowDefinitionBuilder` names each transition
	 * after the action node it lowered, so the lookup is a node lookup by id.
	 * It used to search `edges[]`, because an edge was the transition and the
	 * step rode on it — see `or-flow-action-nodes` for why that inverted.
	 *
	 * @param array $flow The flow document.
	 * @param string $transitionName The transition name, which is a node id.
	 *
	 * @return array The step config, or an empty array when no node matches.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function stepFor(array $flow, string $transitionName): array {
		foreach (($flow['nodes'] ?? []) as $node) {
			if (is_array($node) === false) {
				continue;
			}

			if (trim((string)($node['id'] ?? '')) === $transitionName) {
				return $node;
			}
		}

		return [];
	}//end stepFor()

	/**
	 * The output places a firing node's token actually reaches.
	 *
	 * A token is unique and exclusive: a node may declare several exits, but
	 * exactly ONE of them is taken per firing. Exits are tried in declaration
	 * order — the first whose condition holds wins — and the exit that declares
	 * no condition is the else, taken when nothing matched.
	 *
	 * The else is not optional. `FlowDefinitionBuilder` refuses a branching node
	 * without one, because a token with nowhere to go does not error: the run
	 * simply stops, having reported no failure, which is indistinguishable from
	 * a flow that finished.
	 *
	 * Returns every output place unchanged for a node that does not branch,
	 * which is also how a genuine parallel split keeps working: it has no
	 * conditioned exits, so there is nothing to choose between.
	 *
	 * @param FlowNodeExits  $exits  The fired node within its flow, among its output places ({@see exitsOf()}).
	 * @param FlowStepOutput $output What the step produced and the run-level metadata.
	 *
	 * @return array<string> The output places that receive the token.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function takenExits(FlowNodeExits $exits, FlowStepOutput $output): array {
		$items = $output->items;
		$data  = FlowExpression::documentFor(
			item: ($items[0] ?? []),
			options: new FlowExpressionDataOptions(itemCount: count($items), context: $output->context)
		);
		return $this->exitsTaken(choice: new FlowExitChoice(exits: $exits, data: $data, tags: $this->routedTags(items: $items)));
	}//end takenExits()

	/**
	 * The output places a firing node's token reaches, as {@see takenExits()} documents.
	 *
	 * @param FlowExitChoice $choice The firing node, the expression data and the routed outputs.
	 *
	 * @return array<int, string> The output places this firing should mark.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function exitsTaken(FlowExitChoice $choice): array {
		$node = $this->stepFor(flow: $choice->exits->flow, transitionName: $choice->exits->nodeId);
		if (empty($node['exits'] ?? []) === true) {
			return $choice->exits->candidates;
		}

		// 🔴 THE STEP'S OWN ROUTING DECISION COUNTS. A routing node (the
		// `openregister.route` step) declares UNCONDITIONED exits and tags its
		// items with the output it matched — that tag IS the decision. Reading
		// only edge conditions here meant every routed firing fell through to
		// the first declared exit: the token took a branch by declaration
		// order while the node's log said it had routed the other way.
		// A conditioned exit that holds still wins (a Switch is unchanged);
		// among unconditioned exits, one an item is tagged for — by exit id or
		// by target place — beats the plain declaration-order else.
		return $this->chooseExit(choice: $choice, exits: $node['exits']);
	}//end exitsTaken()

	/**
	 * The firing node of a transition within its flow, among the transition's output places.
	 *
	 * @param array<string, mixed> $flow       The flow document.
	 * @param object               $transition The Symfony transition that fired.
	 *
	 * @return FlowNodeExits The node's exits.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function exitsOf(array $flow, object $transition): FlowNodeExits {
		$candidates = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		return new FlowNodeExits(flow: $flow, nodeId: (string)$transition->getName(), candidates: $candidates);
	}//end exitsOf()

	/**
	 * Try a node's exits in declaration order and return the places of the one taken.
	 *
	 * @param FlowExitChoice           $choice The firing node, the expression data and the routed outputs.
	 * @param array<int|string, mixed> $exits  The node's declared exits, in declaration order.
	 *
	 * @return array<string> The output places that receive the token.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function chooseExit(FlowExitChoice $choice, array $exits): array {
		$tagged = null;
		$fallback = null;

		foreach ($exits as $exit) {
			$targets = $this->targetsOfExit(exits: $choice->exits, exit: $exit);
			if (empty($targets) === true) {
				continue;
			}

			$condition = $this->guardOf(exit: $exit);
			if ($condition === null) {
				$keys = array_merge([(string)($exit['id'] ?? '')], $targets);
				if ($tagged === null && $this->exitIsTagged(keys: $keys, tags: $choice->tags) === true) {
					$tagged = $targets;
				}

				$fallback = ($fallback ?? $targets);
				continue;
			}

			if (FlowExpression::isTrue(logic: $condition, data: $choice->data) === true) {
				return $targets;
			}
		}//end foreach

		// Nothing matched a condition. An exit the items are routed to beats
		// the declaration-order else; the else takes it otherwise. A branching
		// node is required to have one, so reaching null here means the
		// document got past the builder's guard and the token would vanish —
		// return nothing rather than silently broadcasting to every branch.
		return ($tagged ?? $fallback ?? []);
	}//end chooseExit()

	/**
	 * The output places one declared exit reaches; none for a malformed exit.
	 *
	 * @param FlowNodeExits $exits The firing node within its flow, among its candidate places.
	 * @param mixed         $exit  One declared exit.
	 *
	 * @return array<string> The places.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function targetsOfExit(FlowNodeExits $exits, mixed $exit): array {
		if (is_array($exit) === false) {
			return [];
		}

		return $this->placesForExit(exits: $exits, exitId: (string)($exit['id'] ?? ''));
	}//end targetsOfExit()

	/**
	 * The condition an exit declares, or null when it is an unconditioned default.
	 *
	 * @param array<string, mixed> $exit The declared exit.
	 *
	 * @return array|null The guard, or null for a default exit.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function guardOf(array $exit): ?array {
		$condition = ($exit['condition'] ?? null);
		if (is_array($condition) === false || $condition === []) {
			return null;
		}

		return $condition;
	}//end guardOf()

	/**
	 * The set of output tags the produced items carry, keyed for lookup.
	 *
	 * @param array<int, mixed> $items What the step produced.
	 *
	 * @return array<string, true> The tags.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	private function routedTags(array $items): array {
		$tags = [];
		foreach ($items as $item) {
			$tag = FlowItems::outputOf(member: (array)$item);
			if ($tag !== null) {
				$tags[$tag] = true;
			}
		}

		return $tags;
	}//end routedTags()

	/**
	 * Whether any produced item is routed to this exit, by id or by place.
	 *
	 * @param array<int, string>  $keys The exit id and its target places.
	 * @param array<string, bool> $tags The outputs the items are routed to, as keys.
	 *
	 * @return bool True when an item names the exit or one of its places.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	private function exitIsTagged(array $keys, array $tags): bool {
		return array_intersect($keys, array_keys($tags)) !== [];
	}//end exitIsTagged()

	/**
	 * Resolve item output tags that name an EXIT into the exit's place.
	 *
	 * The two halves of per-item routing speak different vocabularies unless
	 * this runs: a routing node tags items with the exit id its rule named
	 * (`output: "no"`), while `FlowItemPlacement::itemsForOutput()` matches
	 * tags against PLACE names (`onRejected`). Left unresolved, every routed
	 * item was silently discarded when the token landed — the branch fired
	 * with zero items and the run persisted `items: []`, losing the seed and
	 * everything the earlier steps produced.
	 *
	 * A tag that already names one of the transition's places is left alone.
	 * A tag naming an exit with exactly one place becomes that place; one
	 * naming an exit with several places is removed, because the whole exit
	 * was taken and an untagged item is broadcast to every taken place. A tag
	 * naming neither is left as-is, keeping the existing drop semantics for a
	 * genuinely unroutable tag.
	 *
	 * @param FlowNodeExits     $exits The fired node within its flow, among its output places ({@see exitsOf()}).
	 * @param array<int, mixed> $items What the step produced.
	 *
	 * @return array<int, mixed> The items, exit-id tags resolved to places.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public function resolveOutputTags(FlowNodeExits $exits, array $items): array {
		return array_map(fn (mixed $item): mixed => $this->resolveItemTag(exits: $exits, item: $item), $items);
	}//end resolveOutputTags()

	/**
	 * Resolve one item's exit-id tag into a place; anything else passes through.
	 *
	 * @param FlowNodeExits $exits The firing node within its flow, among its candidate places.
	 * @param mixed         $item  One item.
	 *
	 * @return mixed The member, its tag resolved when it named an exit.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	private function resolveItemTag(FlowNodeExits $exits, mixed $item): mixed {
		if (is_array($item) === false) {
			return $item;
		}

		$tag = FlowItems::outputOf(member: $item);
		if ($tag === null || in_array($tag, $exits->candidates, true) === true) {
			return $item;
		}

		return $this->retag(item: $item, places: $this->placesForExit(exits: $exits, exitId: $tag));
	}//end resolveItemTag()

	/**
	 * Point an item at the one place its exit reaches, or untag it for several.
	 *
	 * @param array<string, mixed> $item   The item.
	 * @param array<string>        $places The places its tagged exit reaches.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	private function retag(array $item, array $places): array {
		if (count($places) === 1) {
			$item[FlowItems::OUTPUT] = $places[0];
			return $item;
		}

		if (count($places) > 1) {
			unset($item[FlowItems::OUTPUT]);
		}

		return $item;
	}//end retag()

	/**
	 * The output places reached through one named exit.
	 *
	 * @param FlowNodeExits $exits  The node within its flow, among its candidate places.
	 * @param string        $exitId The exit id.
	 *
	 * @return array<int, string> The places, in edge order, without duplicates.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function placesForExit(FlowNodeExits $exits, string $exitId): array {
		$places = [];
		foreach (($exits->flow['edges'] ?? []) as $edge) {
			if ($this->edgeLeavesNode(edge: $edge, nodeId: $exits->nodeId) === false || trim((string)($edge['fromExit'] ?? '')) !== $exitId) {
				continue;
			}

			$places = array_merge($places, $this->targetsAmong(edge: $edge, candidates: $exits->candidates));
		}

		return array_values(array_unique($places));
	}//end placesForExit()

	/**
	 * Whether an edge leaves the given node; the caller matches the exit.
	 *
	 * @param mixed  $edge   One edge of the document.
	 * @param string $nodeId The node.
	 *
	 * @return bool True for a well-formed edge leaving that node.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function edgeLeavesNode(mixed $edge, string $nodeId): bool {
		if (is_array($edge) === false) {
			return false;
		}

		$from = $this->graph->normaliseEndpoints(value: ($edge['from'] ?? null));
		return in_array($nodeId, $from, true);
	}//end edgeLeavesNode()

	/**
	 * The edge's targets that are among the candidate places, in edge order.
	 *
	 * @param array<string, mixed> $edge       The edge.
	 * @param array<string>        $candidates The transition's output places.
	 *
	 * @return array<int, string> The targets.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function targetsAmong(array $edge, array $candidates): array {
		$places = [];
		foreach ($this->graph->normaliseEndpoints(value: ($edge['to'] ?? null)) as $target) {
			if (in_array($target, $candidates, true) === true) {
				$places[] = $target;
			}
		}

		return $places;
	}//end targetsAmong()

	/**
	 * The condition guarding the path into a node, or null when it is a default.
	 *
	 * Used to pick between several ENABLED transitions. Exit selection itself
	 * happens in {@see self::takenExits()}, at the moment a node fires; this is
	 * the read from the other side, for a node deciding whether it is the one
	 * that should run.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 * @param string $nodeId The candidate node.
	 *
	 * @return array|null The guard, or null when the path is unconditional.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function conditionReaching(array $flow, string $nodeId): ?array {
		foreach (($flow['edges'] ?? []) as $edge) {
			if ($this->edgeReaches(edge: $edge, nodeId: $nodeId) === false) {
				continue;
			}

			// An unbranched edge is unconditional: the node it leaves has one
			// way out, so there is nothing to choose between.
			return $this->exitCondition(flow: $flow, edge: $edge);
		}//end foreach

		return null;
	}//end conditionReaching()

	/**
	 * Whether a well-formed edge leads into the given node.
	 *
	 * @param mixed  $edge   The edge.
	 * @param string $nodeId The node.
	 *
	 * @return bool True when the node is among the edge's targets.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function edgeReaches(mixed $edge, string $nodeId): bool {
		if (is_array($edge) === false) {
			return false;
		}

		$targets = $this->graph->normaliseEndpoints(value: ($edge['to'] ?? null));
		return in_array($nodeId, $targets, true);
	}//end edgeReaches()

	/**
	 * The condition an edge's named exit declares, or null when it is a default.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 * @param array<string, mixed> $edge The edge that reaches the node; its `fromExit` names the exit.
	 *
	 * @return array|null The guard, or null when the exit is the default.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function exitCondition(array $flow, array $edge): ?array {
		$exitId = trim((string)($edge['fromExit'] ?? ''));
		if ($exitId === '') {
			return null;
		}

		// The edge may leave several nodes at once, so the one that owns this
		// exit is the one that declares it — not simply "the source". Reading
		// `from` as a scalar named no node at all, which resolved to an empty
		// step, which made every guarded branch look unconditional: the token
		// took a path whose condition was false and the run looked fine.
		foreach ($this->graph->normaliseEndpoints(value: ($edge['from'] ?? null)) as $sourceId) {
			$exit = $this->declaredExit(source: $this->stepFor(flow: $flow, transitionName: $sourceId), exitId: $exitId);
			if ($exit !== null) {
				// An exit that exists but declares no condition is the default
				// branch — reported as unconditional so it becomes the fallback
				// rather than being treated as a failed match.
				return $this->guardOf(exit: $exit);
			}
		}

		// The edge names an exit the node does not declare. Treated as
		// unconditional rather than silently unreachable: a branch that can
		// never be taken is a flow that stops for no visible reason.
		return null;
	}//end exitCondition()

	/**
	 * The exit a node declares under the given id, or null when it has none.
	 *
	 * @param array<string, mixed> $source The node.
	 * @param string               $exitId The exit id.
	 *
	 * @return array<string, mixed>|null The exit.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function declaredExit(array $source, string $exitId): ?array {
		foreach (($source['exits'] ?? []) as $exit) {
			if (is_array($exit) === true && (string)($exit['id'] ?? '') === $exitId) {
				return $exit;
			}
		}

		return null;
	}//end declaredExit()

	/**
	 * Withdraw the tokens the taken exit did not claim.
	 *
	 * `Workflow::apply()` marks EVERY output place, which is correct for a
	 * parallel split and wrong for a choice — and the difference is invisible
	 * until the losing branch runs anyway, one iteration later, with no error.
	 * That is what a token being "unique and exclusive" rules out.
	 *
	 * A node with no conditioned exits takes every output, so a genuine split
	 * passes through here untouched.
	 *
	 * @param FlowWalkHop|FlowStreamHop $hop   The fired hop: its transition, and its pass's workflow and subject.
	 * @param array<string>             $taken The output places the exit claimed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function keepOnlyTakenExits(FlowWalkHop|FlowStreamHop $hop, array $taken): void {
		$tos = array_map(static fn ($t): string => (string)$t, $hop->transition->getTos());
		if (count($taken) !== count($tos)) {
			$pass = $hop->pass;
			$places = array_values(array_diff($tos, $taken));
			$this->withdraw(withdrawal: new FlowTokenWithdrawal(workflow: $pass->workflow, subject: $pass->subject, places: $places));
		}
	}//end keepOnlyTakenExits()

	/**
	 * Unmark the output places of the exits not taken, where the transition marked them.
	 *
	 * @param FlowTokenWithdrawal $withdrawal The workflow, the subject and the places to unmark.
	 *
	 * @return void
	 */
	private function withdraw(FlowTokenWithdrawal $withdrawal): void {
		$marking = $withdrawal->workflow->getMarking(subject: $withdrawal->subject);
		foreach ($withdrawal->places as $place) {
			if ($marking->has($place) === true) {
				$marking->unmark($place);
			}
		}

		$withdrawal->workflow->getMarkingStore()->setMarking(subject: $withdrawal->subject, marking: $marking);
	}//end withdraw()
}//end class
