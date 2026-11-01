<?php

/**
 * One claimed firing of the per-stream walk: the transition, its step, the
 * items it reads, the stream that fires it and the places it claimed.
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

/**
 * A hop of the stream walk, from its claim until it is committed or ends the run.
 */
final class FlowStreamHop extends FlowWalkHop {

	/**
	 * Constructor.
	 *
	 * @param FlowStreamPass     $pass       The pass the hop belongs to.
	 * @param object             $transition The transition being fired.
	 * @param array              $step       The step configuration for the transition.
	 * @param array<int, mixed>  $itemsIn    The items on the transition's input places.
	 * @param string             $streamId   The firing stream.
	 * @param array<int, string> $claimed    The places the claim holds.
	 */
	public function __construct(
		public readonly FlowStreamPass $pass,
		object $transition,
		array $step,
		array $itemsIn,
		public readonly string $streamId,
		public readonly array $claimed,
	) {
		parent::__construct(walk: $pass, transition: $transition, step: $step, itemsIn: $itemsIn);
	}//end __construct()

	/**
	 * The stream fields a stream walk's log entry carries: the stream and its ordinal path.
	 *
	 * @return array{streamId: string, ordinalPath: string} The fields.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function streamTags(): array {
		return ['streamId' => $this->streamId, 'ordinalPath' => $this->pass->streams->pathOf(id: $this->streamId)];
	}//end streamTags()

	/**
	 * The places the firing took, in the transition's declaration order.
	 *
	 * @param array<int, string> $taken The output places the exit claimed.
	 *
	 * @return array<int, string> The taken places, ordered.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function takenInOrder(array $taken): array {
		$takenTos = [];
		foreach ($this->transition->getTos() as $to) {
			if (in_array((string)$to, array_map('strval', $taken), true) === true) {
				$takenTos[] = (string)$to;
			}
		}

		return $takenTos;
	}//end takenInOrder()
}//end class
