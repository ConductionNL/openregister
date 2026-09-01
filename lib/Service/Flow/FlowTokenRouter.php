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
	 * @param array<string, mixed> $flow The flow document.
	 * @param object $transition The fired transition (a node).
	 * @param array<int, mixed> $items What the step produced.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return array<string> The output places that receive the token.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function takenExits(array $flow, object $transition, array $items, array $context): array {
		$all = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		$node = $this->stepFor(flow: $flow, transitionName: $transition->getName());
		if (empty($node['exits'] ?? []) === true) {
			return $all;
		}

		$data = FlowExpression::dataFor(
			item: ($items[0] ?? []),
			itemCount: count($items),
			context: $context
		);

		// 🔴 THE STEP'S OWN ROUTING DECISION COUNTS. A routing node (the
		// `openregister.route` step) declares UNCONDITIONED exits and tags its
		// items with the output it matched — that tag IS the decision. Reading
		// only edge conditions here meant every routed firing fell through to
		// the first declared exit: the token took a branch by declaration
		// order while the node's log said it had routed the other way.
		// A conditioned exit that holds still wins (a Switch is unchanged);
		// among unconditioned exits, one an item is tagged for — by exit id or
		// by target place — beats the plain declaration-order else.
		$tags = [];
		foreach ($items as $item) {
			$tag = FlowItems::outputOf(member: (array)$item);
			if ($tag !== null) {
				$tags[$tag] = true;
			}
		}

		$tagged = null;
		$fallback = null;

		foreach ($node['exits'] as $exit) {
			if (is_array($exit) === false) {
				continue;
			}

			$exitId = (string)($exit['id'] ?? '');
			$targets = $this->placesForExit(
				flow: $flow,
				nodeId: $transition->getName(),
				exitId: $exitId,
				candidates: $all
			);
			if (empty($targets) === true) {
				continue;
			}

			$condition = ($exit['condition'] ?? null);
			if (is_array($condition) === false || $condition === []) {
				if ($tagged === null && (isset($tags[$exitId]) === true || array_intersect($targets, array_keys($tags)) !== [])) {
					$tagged = $targets;
				}

				$fallback = ($fallback ?? $targets);
				continue;
			}

			if (FlowExpression::isTrue(logic: $condition, data: $data) === true) {
				return $targets;
			}
		}//end foreach

		// Nothing matched a condition. An exit the items are routed to beats
		// the declaration-order else; the else takes it otherwise. A branching
		// node is required to have one, so reaching null here means the
		// document got past the builder's guard and the token would vanish —
		// return nothing rather than silently broadcasting to every branch.
		return ($tagged ?? $fallback ?? []);
	}//end takenExits()

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
	 * @param array<string, mixed> $flow The flow document.
	 * @param object $transition The fired transition (a node).
	 * @param array<int, mixed> $items What the step produced.
	 *
	 * @return array<int, mixed> The items, exit-id tags resolved to places.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public function resolveOutputTags(array $flow, object $transition, array $items): array {
		$all = array_map(static fn ($t): string => (string)$t, $transition->getTos());

		foreach ($items as $index => $item) {
			if (is_array($item) === false) {
				continue;
			}

			$tag = FlowItems::outputOf(member: $item);
			if ($tag === null || in_array($tag, $all, true) === true) {
				continue;
			}

			$places = $this->placesForExit(
				flow: $flow,
				nodeId: $transition->getName(),
				exitId: $tag,
				candidates: $all
			);

			if (count($places) === 1) {
				$items[$index][FlowItems::OUTPUT] = $places[0];
			} elseif (count($places) > 1) {
				unset($items[$index][FlowItems::OUTPUT]);
			}
		}

		return $items;
	}//end resolveOutputTags()

	/**
	 * The output places reached through one named exit.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 * @param string $nodeId The firing node.
	 * @param string $exitId The exit.
	 * @param array<string> $candidates The transition's output places.
	 *
	 * @return array<string> The matching places.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function placesForExit(array $flow, string $nodeId, string $exitId, array $candidates): array {
		$places = [];
		foreach (($flow['edges'] ?? []) as $edge) {
			if (is_array($edge) === false) {
				continue;
			}

			$from = $this->graph->normaliseEndpoints(value: ($edge['from'] ?? null));
			if (in_array($nodeId, $from, true) === false) {
				continue;
			}

			if (trim((string)($edge['fromExit'] ?? '')) !== $exitId) {
				continue;
			}

			foreach ($this->graph->normaliseEndpoints(value: ($edge['to'] ?? null)) as $target) {
				if (in_array($target, $candidates, true) === true) {
					$places[] = $target;
				}
			}
		}//end foreach

		return array_values(array_unique($places));
	}//end placesForExit()

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
			if (is_array($edge) === false) {
				continue;
			}

			$targets = $this->graph->normaliseEndpoints(value: ($edge['to'] ?? null));
			if (in_array($nodeId, $targets, true) === false) {
				continue;
			}

			$exitId = trim((string)($edge['fromExit'] ?? ''));
			if ($exitId === '') {
				// An unbranched edge is unconditional: the node it leaves has
				// one way out, so there is nothing to choose between.
				return null;
			}

			return $this->exitCondition(flow: $flow, edge: $edge, exitId: $exitId);
		}//end foreach

		return null;
	}//end conditionReaching()

	/**
	 * The condition an edge's named exit declares, or null when it is a default.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 * @param array<string, mixed> $edge The edge naming the exit.
	 * @param string $exitId The exit id.
	 *
	 * @return array|null The guard, or null when the exit is the default.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function exitCondition(array $flow, array $edge, string $exitId): ?array {
		// The edge may leave several nodes at once, so the one that owns this
		// exit is the one that declares it — not simply "the source". Reading
		// `from` as a scalar named no node at all, which resolved to an empty
		// step, which made every guarded branch look unconditional: the token
		// took a path whose condition was false and the run looked fine.
		foreach ($this->graph->normaliseEndpoints(value: ($edge['from'] ?? null)) as $sourceId) {
			$source = $this->stepFor(flow: $flow, transitionName: $sourceId);
			foreach (($source['exits'] ?? []) as $exit) {
				if (is_array($exit) === false || (string)($exit['id'] ?? '') !== $exitId) {
					continue;
				}

				$condition = ($exit['condition'] ?? null);

				// An exit that exists but declares no condition is the default
				// branch — reported as unconditional so it becomes the fallback
				// rather than being treated as a failed match.
				if (is_array($condition) === true && $condition !== []) {
					return $condition;
				}

				return null;
			}
		}

		// The edge names an exit the node does not declare. Treated as
		// unconditional rather than silently unreachable: a branch that can
		// never be taken is a flow that stops for no visible reason.
		return null;
	}//end exitCondition()

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
	 * @param object $workflow The workflow.
	 * @param object $subject The marking-carrying subject.
	 * @param object $transition The fired transition.
	 * @param array<string> $taken The output places the exit claimed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function keepOnlyTakenExits(object $workflow, object $subject, object $transition, array $taken): void {
		$tos = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		if (count($taken) === count($tos)) {
			return;
		}

		$marking = $workflow->getMarking(subject: $subject);
		foreach ($tos as $place) {
			if (in_array($place, $taken, true) === false && $marking->has($place) === true) {
				$marking->unmark($place);
			}
		}

		$workflow->getMarkingStore()->setMarking(subject: $subject, marking: $marking);

	}//end keepOnlyTakenExits()
}//end class
