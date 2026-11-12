<?php

/**
 * The tier policy and trust lookup one survivorship run resolves with.
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
 * One golden-record resolution: the tiers it ranks with and the trust rows it
 * looks tuples up in. Every candidate-collection and winner-picking step of
 * {@see SurvivorshipResolver} reads both, so they travel as one.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class SurvivorshipRun {

	/**
	 * Bundle the run.
	 *
	 * @param SurvivorshipTierPolicy  $policy The run's tiers.
	 * @param SurvivorshipTrustLookup $trust  The run's trust lookup.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public SurvivorshipTierPolicy $policy,
		public SurvivorshipTrustLookup $trust,
	) {
	}//end __construct()
}//end class
