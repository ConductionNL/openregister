<?php

/**
 * FlowStreamHalt
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

/**
 * The engine's word that one stream stops for this pass: which stream, the
 * claims it held, whether any transition is still enabled, and why.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamHalt {

	/**
	 * Why the stream stopped.
	 *
	 * @var string|null
	 */
	private ?string $reason = null;

	/**
	 * Constructor.
	 *
	 * @param string             $streamId The stream id.
	 * @param array<int, string> $claimed  The claims to release.
	 * @param bool               $enabled  Whether any transition is enabled afterwards.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(
		public readonly string $streamId,
		public readonly array $claimed,
		public readonly bool $enabled,
	) {

	}//end __construct()

	/**
	 * A copy carrying the reason the stream stopped.
	 *
	 * @param string|null $reason The reason.
	 *
	 * @return self The copy.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function because(?string $reason): self {
		$copy = clone $this;
		$copy->reason = $reason;
		return $copy;
	}//end because()

	/**
	 * Why the stream stopped.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function reason(): ?string {
		return $this->reason;
	}//end reason()

	/**
	 * What the pass releases for this halt.
	 *
	 * @param string $owner The pass token.
	 *
	 * @return FlowStreamRelease The release.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function releaseFor(string $owner): FlowStreamRelease {
		return new FlowStreamRelease(claimedPlaces: $this->claimed, owner: $owner, enabled: $this->enabled);
	}//end releaseFor()
}//end class
