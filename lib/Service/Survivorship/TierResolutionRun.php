<?php

/**
 * One tier resolution: the tuple a trust row must match and the instant it must be effective at.
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

use DateTimeImmutable;

/**
 * One trust-tier resolution: the tuple a row must match and the instant it
 * must be effective at. Every row-evaluating step of
 * {@see TrustTierResolver::resolveTier()} reads both, so they travel as one.
 * It never leaves the resolver.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class TierResolutionRun {

	/**
	 * Bundle the run.
	 *
	 * @param TrustTuple        $tuple The (entityType, attribute, sourceSystem) tuple to match.
	 * @param DateTimeImmutable $asOf  Reference instant for effectiveFrom resolution.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public TrustTuple $tuple,
		public DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
