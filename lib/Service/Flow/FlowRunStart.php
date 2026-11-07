<?php

/**
 * What a run starts from, as FlowEngine::run() received it.
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

use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Workflow;

/**
 * The flow, its collaborators and the seed of one run, before a walk is chosen.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
final class FlowRunStart {

	/**
	 * Run-level metadata handed to every step.
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * The seed items.
	 *
	 * @var array<int, mixed>
	 */
	public array $items = [];

	/**
	 * The per-run stream collaborator; null walks a single in-memory stream.
	 *
	 * @var FlowStreamWalk|null
	 */
	public ?FlowStreamWalk $streams = null;

	/**
	 * The items per place, once the workflow is known.
	 *
	 * @var array<string, array>
	 */
	public array $placeItems = [];

	/**
	 * Set up the start.
	 *
	 * @param array                 $flow       The flow document.
	 * @param MarkingStoreInterface $store      Where the marking lives.
	 * @param object                $subject    The object the run is about.
	 * @param FlowStepDispatcher    $dispatcher Performs each step's side effect.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly array $flow,
		public readonly MarkingStoreInterface $store,
		public readonly object $subject,
		public readonly FlowStepDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * The result of a run that could not start.
	 *
	 * @param string $error Why it could not.
	 *
	 * @return array The failed run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function refused(string $error): array {
		return [
			'status' => FlowEngine::STATUS_FAILED,
			'log' => [],
			'context' => $this->context,
			'items' => $this->items,
			'error' => $error,
		];
	}//end refused()

	/**
	 * The pass of the single in-memory stream walk.
	 *
	 * @param Workflow $workflow The workflow built from the flow.
	 *
	 * @return FlowWalkPass The pass.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function walkPass(Workflow $workflow): FlowWalkPass {
		$pass = new FlowWalkPass(flow: $this->flow, workflow: $workflow, subject: $this->subject, dispatcher: $this->dispatcher);
		$pass->context = $this->context;
		$pass->items = $this->items;
		$pass->placeItems = $this->placeItems;

		return $pass;
	}//end walkPass()

	/**
	 * The pass of the per-stream walk.
	 *
	 * @param Workflow       $workflow The workflow built from the flow.
	 * @param FlowStreamWalk $streams  The per-run stream collaborator.
	 *
	 * @return FlowStreamPass The pass.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function streamPass(Workflow $workflow, FlowStreamWalk $streams): FlowStreamPass {
		$pass = new FlowStreamPass(
			flow: $this->flow,
			workflow: $workflow,
			store: $this->store,
			subject: $this->subject,
			dispatcher: $this->dispatcher,
			context: $this->context,
			streams: $streams
		);
		$pass->items = $this->items;
		$pass->placeItems = $this->placeItems;

		return $pass;
	}//end streamPass()
}//end class
