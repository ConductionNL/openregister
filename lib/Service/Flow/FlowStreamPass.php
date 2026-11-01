<?php

/**
 * One pass of the per-stream walk: what the pass walks over, and the log,
 * items and per-place items it builds up as it goes.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Workflow;

/**
 * The per-pass state of FlowEngine's stream walk.
 *
 * A walk that also holds the marking store and the stream collaborator.
 */
final class FlowStreamPass extends FlowWalk {

	/**
	 * Constructor.
	 *
	 * @param array                 $flow       The flow document.
	 * @param Workflow              $workflow   The Petri net over the run's marking store.
	 * @param MarkingStoreInterface $store      The marking store (synced from each commit).
	 * @param object                $subject    The subject holding the marking.
	 * @param FlowStepDispatcher    $dispatcher Performs each step's side effect.
	 * @param array                 $context    Run-level metadata handed to every step.
	 * @param FlowStreamWalk        $streams    The stream collaborator.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) A value object carrying the pass's collaborators
	 */
	public function __construct(
		array $flow,
		Workflow $workflow,
		public readonly MarkingStoreInterface $store,
		object $subject,
		FlowStepDispatcher $dispatcher,
		array $context,
		public readonly FlowStreamWalk $streams,
	) {
		parent::__construct(flow: $flow, workflow: $workflow, subject: $subject, dispatcher: $dispatcher, context: $context);
	}//end __construct()

	/**
	 * The enabled transitions consuming from the place a stream's token sits on.
	 *
	 * @param string $streamId The stream.
	 *
	 * @return array<int, object> The candidate transitions, in declaration order.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function candidatesFor(string $streamId): array {
		$place = $this->streams->placeOf(id: $streamId);
		$candidates = [];
		foreach ($this->workflow->getEnabledTransitions(subject: $this->subject) as $transition) {
			if ($place !== null && in_array($place, array_map('strval', $transition->getFroms()), true) === true) {
				$candidates[] = $transition;
			}
		}

		return $candidates;
	}//end candidatesFor()
}//end class
