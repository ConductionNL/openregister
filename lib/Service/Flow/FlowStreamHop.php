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
final class FlowStreamHop {

	/**
	 * When the hop started, as `microtime(true)` returns it.
	 *
	 * @var float
	 */
	public readonly float $startedAt;

	/**
	 * Constructor. The start moment is taken when the hop is made.
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
		public readonly object $transition,
		public readonly array $step,
		public readonly array $itemsIn,
		public readonly string $streamId,
		public readonly array $claimed,
	) {
		$this->startedAt = microtime(true);
	}//end __construct()

	/**
	 * The transition name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function name(): string {
		return (string)$this->transition->getName();
	}//end name()

	/**
	 * The step's catalogue id, carried onto every log entry.
	 *
	 * @return string The step type, or an empty string.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function stepType(): string {
		return (string)($this->step['type'] ?? '');
	}//end stepType()
}//end class
