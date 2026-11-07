<?php

/**
 * OpenRegister SurvivorshipPolicy
 *
 * The tier settings one golden-record resolution reads from the survivorship config.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Survivorship
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Survivorship;

/**
 * Tier order, default tier, discard tier and freshness anchor field of one resolution.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final class SurvivorshipPolicy {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $tierOrder   Ordered tier names, weakest first.
	 * @param string             $defaultTier Fallback tier for an unmatched tuple.
	 * @param string             $discardTier Tier excluded from competing.
	 * @param string             $anchorField Field holding the freshness anchor date; empty for none.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public readonly array $tierOrder,
		public readonly string $defaultTier,
		public readonly string $discardTier,
		public readonly string $anchorField,
	) {
	}//end __construct()
}//end class
