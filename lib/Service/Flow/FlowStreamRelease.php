<?php

/**
 * FlowStreamRelease
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
 * What a pass gives back when one of its streams stops: the claims it held,
 * its token, and whether any transition is still enabled afterwards.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */
final class FlowStreamRelease {

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $claimedPlaces The claims to release.
	 * @param string             $owner         The pass token.
	 * @param bool               $enabled       Whether any transition is enabled afterwards.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function __construct(
		public readonly array $claimedPlaces,
		public readonly string $owner,
		public readonly bool $enabled,
	) {

	}//end __construct()
}//end class
