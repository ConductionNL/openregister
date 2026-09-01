<?php

/**
 * Where a flow run's items sit, and where they go when a node fires.
 *
 * The second half of the split out of `FlowEngine`. `FlowTokenRouter` answers
 * "which exit does the token take"; this answers "which items are on which
 * place, and which of them travel". Keeping them apart is not only about the
 * complexity threshold: exit selection reads the DOCUMENT (nodes, exits, edge
 * conditions) while placement reads the MARKING, and the two were only ever
 * coupled through the taken-exit list they hand each other.
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
 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Workflow;

/**
 * Holds the per-place item buffers and moves items as transitions fire.
 *
 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
 */
class FlowItemPlacement {
	/**
	 * Gather the items a transition reads: every input place's items, in the
	 * froms' declared order.
	 *
	 * For a normal step this is just its one input place. For a join it is the
	 * concatenation of every incoming branch's items — which is what a Merge
	 * node receives and then combines.
	 *
	 * @param object $transition The transition.
	 * @param array<string, array> $placeItems Items per place.
	 *
	 * @return array<int, mixed> The gathered input items.
	 *
	 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
	 */
	public function itemsForTransition(object $transition, array $placeItems): array {
		$items = [];
		foreach ($transition->getFroms() as $from) {
			foreach (($placeItems[(string)$from] ?? []) as $item) {
				$items[] = $item;
			}
		}

		return $items;
	}//end itemsForTransition()

	/**
	 * Seed the per-place item buffers from the current marking.
	 *
	 * Items belong to the PLACES a token sits on, not to the run globally: a
	 * parallel split hands each branch the items from the split point, and a
	 * join reads the items every incoming branch left on it. A single shared
	 * list cannot express either — the second branch to run would overwrite
	 * the first.
	 *
	 * Seeded from the CURRENT marking, which is what makes resume work: a fresh
	 * run's marking is the initial place, a resumed run's is wherever it
	 * suspended, and either way the stored items land on the place that holds
	 * the token.
	 *
	 * @param Workflow $workflow The workflow.
	 * @param object $subject The subject holding the marking.
	 * @param Definition $definition The definition (for the initial-place fallback).
	 * @param array $items The seed items.
	 * @param array<string, array>|null $stored The per-place items persisted by the last commit, or null when none.
	 *
	 * @return array<string, array> Items keyed by place.
	 *
	 * @spec openspec/changes/or-flow-merge/specs/flow-merge/spec.md
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function seedPlaceItems(Workflow $workflow, object $subject, Definition $definition, array $items, ?array $stored = null): array {
		$placeItems = [];
		foreach (array_keys($workflow->getMarking(subject: $subject)->getPlaces()) as $place) {
			// PER-PLACE items persisted by the last commit win: a stream that
			// suspended holding two tokens resumes with each branch carrying
			// the items ITS branch produced. The same-list-to-every-place seed
			// below is kept for a run that predates the column (null), so an
			// in-flight run's behaviour across the upgrade is identical.
			if ($stored !== null && array_key_exists((string)$place, $stored) === true) {
				$placeItems[(string)$place] = (array)$stored[(string)$place];
				continue;
			}

			$placeItems[(string)$place] = $items;
		}

		if ($placeItems === []) {
			foreach ($definition->getInitialPlaces() as $place) {
				$placeItems[(string)$place] = $items;
			}
		}

		return $placeItems;
	}//end seedPlaceItems()

	/**
	 * Move a fired transition's items: onto its output places, off its inputs.
	 *
	 * Per-item routing (n8n's If/Switch) lives here. An item that names an output
	 * ({@see FlowItems::OUTPUT}, set by a routing node) goes only to the output
	 * place with that name; an item that names none is broadcast to every output,
	 * which is the ordinary behaviour and what a parallel split relies on. So a
	 * step whose items carry no output tag distributes exactly as before — this
	 * is additive, not a change to any existing flow. The tag is stripped as the
	 * item lands, so it never lingers to misroute a later step.
	 *
	 * Clearing the consumed inputs matters for a loop that re-enters the
	 * transition — it must read fresh items, not a stale copy left behind.
	 *
	 * A firing that produced NO items only clears its inputs. Writing empty
	 * lists to its outputs would erase items another branch has already placed
	 * on a place they share, which is openregister#2488 and is invisible in a
	 * run log — see the comment in the body.
	 *
	 * @param object $transition The fired transition.
	 * @param array<string, array> $placeItems The current per-place buffers.
	 * @param array $items What the step produced.
	 * @param array<string> $taken The output places the exit claimed.
	 *
	 * @return array<string, array> The updated buffers.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public function advanceItems(object $transition, array $placeItems, array $items, array $taken): array {
		// A FIRING THAT PRODUCED NOTHING PLACES NOTHING AND DESTROYS NOTHING.
		//
		// Every node in a flow fires on every pass, and most of them fire with
		// no items — the familiar `in 0 out 0` heartbeat in a run log. Without
		// this guard each of those heartbeats still ran the loop below and
		// ASSIGNED an empty list to every one of its output places. A node that
		// shares an output place with a branch scheduled later in the same pass
		// therefore had its items erased before the consumer ever ran.
		//
		// Measured in hydra's sequencer (openregister#2488), twice:
		//
		//     built-gate     in 1 out 1   item tagged output: "build-blocked"
		//     verdict-gate   in 0 out 0   heartbeat, in ANOTHER branch
		//     build-blocked  in 0 out 0   nothing arrived
		//
		// The routing was correct in both cases — the emitted item carried the
		// right output tag, visible in the run log's own output envelope. The
		// loss was entirely here. And nothing reported it: the run said
		// `completed`, the routing node said `in 1 out 1`, and the starved
		// consumer said `completed` too, because a node with no items still
		// fires. The only trace anywhere was an `in 0` on a node nobody was
		// watching.
		//
		// The comment below justifies the unconditional write as keeping stale
		// items off a branch the token never reached. That reasoning is about
		// PLACING items, and there are none to place here — so returning early
		// cannot seed anything. The input places are still cleared, because
		// consuming them is what the firing did do.
		if ($items === []) {
			foreach ($transition->getFroms() as $from) {
				unset($placeItems[(string)$from]);
			}

			return $placeItems;
		}

		foreach ($transition->getTos() as $to) {
			// Only the taken exit receives items. Seeding a branch the token
			// never reaches would leave stale items for a later firing to pick
			// up as if they were fresh.
			if (in_array((string)$to, $taken, true) === false) {
				unset($placeItems[(string)$to]);
				continue;
			}

			$placeItems[(string)$to] = $this->itemsForOutput(items: $items, output: (string)$to);
		}

		foreach ($transition->getFroms() as $from) {
			unset($placeItems[(string)$from]);
		}

		return $placeItems;
	}//end advanceItems()

	/**
	 * The items that belong on one output place: those routed to it, plus the
	 * unrouted ones that go everywhere. The output tag is dropped on the way.
	 *
	 * @param array<int, array> $items The produced items.
	 * @param string $output The output place's name.
	 *
	 * @return array<int, array> The items for that output, tag removed.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public function itemsForOutput(array $items, string $output): array {
		// A routing step tags an item with the NODE it is routing to, and a
		// node's input place is named after the node — so the tag and the place
		// name are normally the same string. The one exception is a declared
		// join, whose input places are `<node>#<edge>` so it can require a token
		// on each. Comparing the raw place name there would match nothing and
		// silently drop every routed item into an empty branch, which is the
		// failure mode this engine exists to refuse rather than produce.
		$target = $output;
		$split = strpos($output, '#');
		if ($split !== false) {
			$target = substr($output, 0, $split);
		}

		$out = [];
		foreach ($items as $item) {
			$tag = FlowItems::outputOf(member: (array)$item);
			if ($tag !== null && $tag !== $target) {
				// Routed elsewhere: not this output's item.
				continue;
			}

			unset($item[FlowItems::OUTPUT]);
			$out[] = $item;
		}

		return $out;
	}//end itemsForOutput()
}//end class
