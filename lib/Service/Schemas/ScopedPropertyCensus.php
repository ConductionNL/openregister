<?php

/**
 * OpenRegister scoped property governance: a census of values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use DateTimeImmutable;

/**
 * How many values each property holds, as counted at one moment (ADR-011 Rule 2).
 *
 * The caller counts, because counting values is a query over the objects
 * table that ScopedPropertyGovernance does not own; a property missing from
 * the counts was not counted, which is not the same as holding none.
 *
 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
 */
final class ScopedPropertyCensus {
	/**
	 * Hold the counts and when they were read.
	 *
	 * @param array<string, int> $counts How many values each property holds.
	 * @param DateTimeImmutable  $asOf   When the counts were read.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly array $counts,
		public readonly DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
