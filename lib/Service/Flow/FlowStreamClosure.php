<?php

/**
 * FlowStreamClosure
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;


use DateTime;
use OCA\OpenRegister\Db\FlowRun;

/**
 * One stream stopping: parked on a suspension with its wake time, or ended
 * in a terminal status, with its reason and what the pass releases.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamClosure {

	/**
	 * The wake time of a parked stream; null while waiting on a signal.
	 *
	 * @var DateTime|null
	 */
	private ?DateTime $resumeAt = null;

	/**
	 * Why the stream stopped.
	 *
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param FlowStreamRef     $stream  The stream.
	 * @param string            $status  FlowRun::STATUS_SUSPENDED to park, or one of FlowRun::TERMINAL.
	 * @param FlowStreamRelease $release What the pass releases.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(
		public readonly FlowStreamRef $stream,
		public readonly string $status,
		public readonly FlowStreamRelease $release,
	) {

	}//end __construct()

	/**
	 * A copy that wakes at the given time.
	 *
	 * @param DateTime|null $resumeAt The wake time; null while waiting on a signal.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function wakingAt(?DateTime $resumeAt): self {
		$copy = clone $this;
		$copy->resumeAt = $resumeAt;
		return $copy;
	}//end wakingAt()

	/**
	 * A copy carrying the reason.
	 *
	 * @param string|null $error The reason.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function because(?string $error): self {
		$copy = clone $this;
		$copy->error = $error;
		return $copy;
	}//end because()

	/**
	 * Whether this parks the stream rather than ending it.
	 *
	 * @return bool True for a suspension.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function parks(): bool {
		return $this->status === FlowRun::STATUS_SUSPENDED;
	}//end parks()

	/**
	 * The wake time.
	 *
	 * @return DateTime|null The wake time.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function resumeAt(): ?DateTime {
		return $this->resumeAt;
	}//end resumeAt()

	/**
	 * The reason.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function error(): ?string {
		return $this->error;
	}//end error()
}//end class
