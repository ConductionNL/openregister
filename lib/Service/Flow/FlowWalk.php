<?php

/**
 * One walk of a flow: what the walk runs over, and the log, items and
 * per-place items it builds up as it goes.
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
 * The state of one FlowEngine walk.
 *
 * The constructor arguments are fixed for the walk. The log, the items in hand
 * and the items per place change with every hop.
 */
class FlowWalk {

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
	 * Constructor.
	 *
	 * @param array              $flow       The flow document.
	 * @param Workflow           $workflow   The Petri net over the run's marking store.
	 * @param object             $subject    The subject holding the marking.
	 * @param FlowStepDispatcher $dispatcher Performs each step's side effect.
	 * @param array              $context    Run-level metadata handed to every step.
	 */
	public function __construct(
		public readonly array $flow,
		public readonly Workflow $workflow,
		public readonly object $subject,
		public readonly FlowStepDispatcher $dispatcher,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * The run result envelope of the walk: status, log, context and items.
	 *
	 * @param string $status The run status.
	 *
	 * @return array{status: string, log: array, context: array, items: array} The envelope.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function result(string $status): array {
		return ['status' => $status, 'log' => $this->log, 'context' => $this->context, 'items' => $this->items];
	}//end result()

	/**
	 * The terminal result of a stop raised by a Stop step or an oversight check.
	 *
	 * A stop raised as an error ends the run `failed` with the stop's message;
	 * any other stop ends it `stopped`, with no error.
	 *
	 * @param FlowStop $stop The stop.
	 *
	 * @return array The run result, with `error`.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function stoppedResult(FlowStop $stop): array {
		if ($stop->isError() === true) {
			return $this->result(status: FlowEngine::STATUS_FAILED) + ['error' => $stop->getMessage()];
		}

		return $this->result(status: FlowEngine::STATUS_STOPPED) + ['error' => null];
	}//end stoppedResult()

	/**
	 * The pinned output for a step, or null when it is not pinned.
	 *
	 * Pins are a map of step name to an item list. A run carries them in its
	 * `context` under `pins` (a test/authoring run supplies them without
	 * touching the stored flow); a flow may also carry a `pins` map of its own,
	 * used only as the fallback so a run's pins always win. A step whose name is
	 * absent from both is not pinned and runs normally.
	 *
	 * @param string $transitionName The step's transition name.
	 *
	 * @return array<int, mixed>|null The pinned items, or null when not pinned.
	 *
	 * @spec openspec/changes/or-flow-pins/specs/flow-pins/spec.md
	 */
	public function pinnedFor(string $transitionName): ?array {
		$pins = (array)($this->context['pins'] ?? ($this->flow['pins'] ?? []));
		if (array_key_exists($transitionName, $pins) === false) {
			return null;
		}

		return FlowItems::normalise(value: $pins[$transitionName]);
	}//end pinnedFor()
}//end class
