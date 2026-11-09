<?php

/**
 * The trust lookup one survivorship run reads.
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
 * The entity type, trust rows, trust engine and reference instant of one run.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
 */
final readonly class SurvivorshipTrustLookup {

	/**
	 * Bundle the lookup.
	 *
	 * @param string                           $entityType    Entity type.
	 * @param array<int, array<string, mixed>> $trustRows     Candidate trust-configuration rows.
	 * @param TrustTierResolverInterface       $trustResolver Trust lookup + decay engine.
	 * @param DateTimeImmutable                $asOf          Reference instant.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#2.1
	 */
	public function __construct(
		public string $entityType,
		public array $trustRows,
		public TrustTierResolverInterface $trustResolver,
		public DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
