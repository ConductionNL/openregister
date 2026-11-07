<?php

/**
 * OpenRegister SurvivorshipTrust
 *
 * The trust lookup one golden-record resolution asks for every candidate value.
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

use DateTimeImmutable;

/**
 * Entity type, trust rows, trust resolver and reference instant of one resolution.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final class SurvivorshipTrust {
	/**
	 * Constructor.
	 *
	 * @param string                           $entityType    Entity type passed through to the trust lookup.
	 * @param array<int, array<string, mixed>> $trustRows     Candidate trust-configuration rows.
	 * @param TrustTierResolver                $trustResolver Trust lookup + decay engine.
	 * @param DateTimeImmutable                $asOf          Reference instant for effectiveFrom + decay.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly array $trustRows,
		public readonly TrustTierResolver $trustResolver,
		public readonly DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
