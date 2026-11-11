<?php

/**
 * Plans each hop of a walk and moves its token and items once it fired.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Symfony\Component\Workflow\Workflow;

/**
 * What both walks of FlowEngine do around a step: the same plan, the same advance.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
final class FlowHopRouting {
	/**
	 * Set up the routing.
	 *
	 * @param FlowTokenRouter       $router    Decides which exit a token takes.
	 * @param FlowItemPlacement     $placement Decides which items sit on which place.
	 * @param FlowTaskMootness|null $mootness  Told which places a routing decision cleared.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function __construct(
		private readonly FlowTokenRouter $router,
		private readonly FlowItemPlacement $placement,
		private readonly ?FlowTaskMootness $mootness,
	) {
	}//end __construct()

	/**
	 * The items per place a run starts with.
	 *
	 * Items belong to the PLACES a token sits on, not to the run globally. With
	 * a stream walk the buffers persisted by the last commit win, so each
	 * branch resumes with the items ITS branch produced.
	 *
	 * @param FlowRunStart $start    What the run starts from.
	 * @param Workflow     $workflow The workflow of the run.
	 *
	 * @return array<string, array> The items per place.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function seed(FlowRunStart $start, Workflow $workflow): array {
		return $this->placement->seed(
			seed: new ItemSeed(
				workflow: $workflow,
				subject: $start->subject,
				items: $start->items,
				stored: $start->streams?->run()->getPlaceItems()
			)
		);
	}//end seed()

	/**
	 * What a chosen transition fires: its step and the items on its input places.
	 *
	 * @param object                      $transition The chosen transition.
	 * @param FlowWalkPass|FlowStreamPass $pass       The pass.
	 *
	 * @return FlowHopPlan The plan.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function plan(object $transition, FlowWalkPass|FlowStreamPass $pass): FlowHopPlan {
		return new FlowHopPlan(
			transition: $transition,
			step: $this->router->stepFor(flow: $pass->flow, transitionName: $transition->getName()),
			itemsIn: $this->placement->itemsForTransition(transition: $transition, placeItems: $pass->placeItems)
		);
	}//end plan()

	/**
	 * Fire a hop on the workflow: both walks move the token and the items this way.
	 *
	 * @param FlowWalkHop|FlowStreamHop $hop The fired hop; its pass holds the items produced.
	 *
	 * @return FlowHopAdvance The items, the items per place and the exits taken.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function advance(FlowWalkHop|FlowStreamHop $hop): FlowHopAdvance {
		$pass = $hop->pass;
		$transition = $hop->transition;
		$exits = $this->router->exitsOf(flow: $pass->flow, transition: $transition);
		$items = $this->router->resolveOutputTags(exits: $exits, items: $pass->items);
		$taken = $this->router->takenExits(exits: $exits, output: new FlowStepOutput(items: $items, context: $pass->context));
		$firing = new ItemFiring(transition: $transition, items: $items, taken: $taken);
		$placeItems = $this->placement->advance(firing: $firing, placeItems: $pass->placeItems);
		$pass->workflow->apply(subject: $pass->subject, transitionName: $hop->name());
		$this->pruneUntakenExits(hop: $hop, taken: $taken);

		return new FlowHopAdvance(items: $items, placeItems: $placeItems, taken: $taken);
	}//end advance()

	/**
	 * Withdraw the tokens a choice did not take, and say so.
	 *
	 * The withdrawal itself is the router's. What is added here is the report:
	 * a place cleared by a routing decision may be one a user-task node had
	 * already asked somebody from, and that node will now never fire from it,
	 * so its task is moot and must not stay in an inbox as actionable work.
	 * The Petri net raises no event for this; the engine is the only thing
	 * that sees the moment, so the engine reports it (design D-7).
	 *
	 * @param FlowWalkHop|FlowStreamHop $hop   The hop that just fired.
	 * @param array<string>             $taken The output places the exit claimed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	private function pruneUntakenExits(FlowWalkHop|FlowStreamHop $hop, array $taken): void {
		$pass = $hop->pass;
		$transition = $hop->transition;
		$tos = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		$pruned = array_values(array_diff($tos, $taken));

		$this->router->keepOnlyTakenExits(hop: $hop, taken: $taken);

		if ($pruned === []) {
			return;
		}

		$this->mootness?->placesPruned(context: $pass->context, pruning: new PlacesPruning(places: $pruned, byTransition: $transition->getName()));
	}//end pruneUntakenExits()
}//end class
