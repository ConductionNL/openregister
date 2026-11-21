<?php

/**
 * FlowPassFiring
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

use InvalidArgumentException;

/**
 * One firing together with the pass that fired it: the pass token is the owner whose claims on the firing's
 * places the commit releases and whose remaining claims do not count as foreign. A firing without an owner
 * cannot be built.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */
final class FlowPassFiring {

	/**
	 * Constructor.
	 *
	 * @param FlowFiring $firing What fired, on which stream, with what effect.
	 * @param string     $owner  The pass token; never empty.
	 *
	 * @throws InvalidArgumentException When the owner is empty.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function __construct(
		public readonly FlowFiring $firing,
		public readonly string $owner,
	) {
		if (trim($owner) === '') {
			throw new InvalidArgumentException('A firing is committed by a pass: its owner token cannot be empty.');
		}
	}//end __construct()

	/**
	 * What the commit releases: the firing's claims, under this pass's token.
	 *
	 * @return FlowStreamRelease
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function release(): FlowStreamRelease {
		return new FlowStreamRelease(
			claimedPlaces: $this->firing->getClaimedPlaces(),
			owner: $this->owner,
			enabled: $this->firing->isEnabledAfter()
		);
	}//end release()
}//end class
