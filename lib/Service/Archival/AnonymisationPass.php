<?php

/**
 * One pass of a salted profile over a payload.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The payload as the pass has treated it so far, and the profile it applies.
 *
 * Never leaves AnonymisationService.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class AnonymisationPass {
	/**
	 * Start the pass on the untouched payload.
	 *
	 * @param SaltedProfile        $profile The rules and their salt.
	 * @param array<string, mixed> $payload The payload, treated in place as the pass runs.
	 */
	public function __construct(
		public readonly SaltedProfile $profile,
		public array $payload,
	) {
	}//end __construct()
}//end class
