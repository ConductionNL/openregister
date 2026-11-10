<?php

/**
 * What one golden-record resolution reads.
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
 * The linked source records, the survivorship annotation, the trust lookup and the
 * optional per-object overrides one {@see SurvivorshipResolver::resolve()} call weighs.
 *
 * @spec openspec/changes/mdm-survivorship-override/tasks.md#1.1
 */
final class GoldenRecordRequest {

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $sourceRecords Linked source records.
	 * @param array<string, mixed>             $config        The `x-openregister-survivorship` annotation.
	 * @param SurvivorshipTrustLookup          $trust         Entity type, trust rows, trust engine and reference instant.
	 * @param mixed                            $overrides     Optional per-object attribute-override map; untrusted input.
	 */
	public function __construct(
		public readonly array $sourceRecords,
		public readonly array $config,
		public readonly SurvivorshipTrustLookup $trust,
		public readonly mixed $overrides = null,
	) {
	}//end __construct()
}//end class
