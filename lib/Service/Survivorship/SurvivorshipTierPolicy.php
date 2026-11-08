<?php

/**
 * The tier policy one survivorship run applies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * The tiers of one `x-openregister-survivorship` annotation, read once per run.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class SurvivorshipTierPolicy {

	/**
	 * Bundle the policy.
	 *
	 * @param array<int, string> $tierOrder   Ordered tier names, weakest first.
	 * @param string             $defaultTier Fallback tier for an unmatched tuple.
	 * @param string             $discardTier Tier excluded from competing.
	 * @param string             $anchorField Field holding the freshness anchor date.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public array $tierOrder,
		public string $defaultTier,
		public string $discardTier,
		public string $anchorField,
	) {
	}//end __construct()
}//end class
