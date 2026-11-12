<?php

/**
 * A tier and the ordered tiers it sits among.
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
 * The starting tier of a source and the tier order it can step down along,
 * as {@see TrustTierResolver::applyFreshnessDecay()} reads them.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#1.1
 */
final class TierPosition {

	/**
	 * Constructor.
	 *
	 * @param string             $tier      Starting tier.
	 * @param array<int, string> $tierOrder Ordered tier names, weakest first.
	 */
	public function __construct(
		public readonly string $tier,
		public readonly array $tierOrder,
	) {
	}//end __construct()
}//end class
