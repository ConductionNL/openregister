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
 * The constructor arguments are fixed for the pass. The log, the items in hand
 * and the items per place change with every firing.
 */
final class FlowStreamPass {

	/**
	 * The run log so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $log = [];

	/**
	 * The items in hand, returned with the result.
	 *
	 * @var array<int, mixed>
	 */
	public array $items = [];

	/**
	 * Items per place.
	 *
	 * @var array<string, array>
	 */
	public array $placeItems = [];

	/**
	 * The flow document.
	 *
	 * @var array
	 */
	public readonly array $flow;

	/**
	 * The marking store (synced from each commit).
	 *
	 * @var MarkingStoreInterface
	 */
	public readonly MarkingStoreInterface $store;

	/**
	 * The subject holding the marking.
	 *
	 * @var object
	 */
	public readonly object $subject;

	/**
	 * Performs each step's side effect.
	 *
	 * @var FlowStepDispatcher
	 */
	public readonly FlowStepDispatcher $dispatcher;

	/**
	 * Run-level metadata handed to every step.
	 *
	 * @var array
	 */
	public readonly array $context;

	/**
	 * The stream collaborator.
	 *
	 * @var FlowStreamWalk
	 */
	public readonly FlowStreamWalk $streams;

	/**
	 * Constructor.
	 *
	 * The flow, store, subject, dispatcher, context and stream collaborator
	 * are taken from the run start as they stand when the pass is made; the
	 * start must carry its streams.
	 *
	 * @param FlowRunStart $start    The run start the pass walks for.
	 * @param Workflow     $workflow The Petri net over the run's marking store.
	 */
	public function __construct(
		FlowRunStart $start,
		public readonly Workflow $workflow,
	) {
		$this->streams    = $start->streams;
		$this->flow       = $start->flow;
		$this->store      = $start->store;
		$this->subject    = $start->subject;
		$this->dispatcher = $start->dispatcher;
		$this->context    = $start->context;
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

	/**
	 * The run result envelope of the pass: status, log, context and items.
	 *
	 * @param string $status The run status.
	 *
	 * @return array{status: string, log: array, context: array, items: array} The envelope.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function result(string $status): array {
		return ['status' => $status, 'log' => $this->log, 'context' => $this->context, 'items' => $this->items];
	}//end result()

	/**
	 * The pass's last word, under the lock, from the marking there.
	 *
	 * "Work remains" excludes the transitions parked streams keep enabled: a
	 * wait is not work, and counting it would re-queue every parked run.
	 *
	 * @return array The run result.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function finish(): array {
		$enabled = $this->streams->workRemains(transitions: $this->workflow->getEnabledTransitions(subject: $this->subject));
		$status = $this->streams->finalize(enabled: $enabled);

		$result = $this->result(status: $status);
		if ($status === FlowEngine::STATUS_SUSPENDED) {
			$result['resumeAt'] = $this->streams->run()->getResumeAt();
		}

		return $result;
	}//end finish()
}//end class
