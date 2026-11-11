<?php

/**
 * Which streams a worker pass still visits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * The streams one pass has set aside: parked on a suspension, refused a
 * claim, or found nothing to fire on their last visit; and, for an
 * in-request advance, the one stream the pass is limited to.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamVisits {

	/**
	 * Streams parked during this pass (suspended), by id.
	 *
	 * @var array<string, true>
	 */
	private array $parked = [];

	/**
	 * Streams that found no enabled transition on their last visit, by id.
	 * Cleared whenever a commit changes the marking, because a sibling's
	 * commit may have enabled a join.
	 *
	 * @var array<string, true>
	 */
	private array $exhausted = [];

	/**
	 * Streams whose claim was refused this pass, by id. They stay enabled and
	 * leave the run `queued` for the next pass.
	 *
	 * @var array<string, true>
	 */
	private array $refused = [];

	/**
	 * Constructor.
	 *
	 * @param string|null $onlyStream Restrict the pass to one stream (an in-request advance).
	 */
	public function __construct(
		private readonly ?string $onlyStream = null,
	) {
	}//end __construct()

	/**
	 * Whether the pass may visit this stream: in scope and not set aside.
	 *
	 * @param string $id The stream id.
	 *
	 * @return bool True when visitable.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function mayVisit(string $id): bool {
		if ($this->onlyStream !== null && $id !== $this->onlyStream) {
			return false;
		}

		return isset($this->parked[$id]) === false
			&& isset($this->refused[$id]) === false
			&& isset($this->exhausted[$id]) === false;
	}//end mayVisit()

	/**
	 * Whether the stream parked during this pass.
	 *
	 * @param int|string $id The stream id, as an array key.
	 *
	 * @return bool True when parked.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function isParked(int|string $id): bool {
		return isset($this->parked[$id]);
	}//end isParked()

	/**
	 * Note that a stream parked on a suspension.
	 *
	 * @param string $id The stream id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function park(string $id): void {
		$this->parked[$id] = true;
	}//end park()

	/**
	 * Note that a stream's claim was refused.
	 *
	 * @param string $id The stream id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function refuse(string $id): void {
		$this->refused[$id] = true;
	}//end refuse()

	/**
	 * Note that a stream found nothing to fire on this visit.
	 *
	 * @param string $id The stream id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function exhaust(string $id): void {
		$this->exhausted[$id] = true;
	}//end exhaust()

	/**
	 * Make every exhausted stream visitable again, after a commit changed the marking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function forgetExhausted(): void {
		$this->exhausted = [];
	}//end forgetExhausted()
}//end class
