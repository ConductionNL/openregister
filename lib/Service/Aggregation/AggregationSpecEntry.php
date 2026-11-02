<?php

/**
 * OpenRegister AggregationSpecEntry
 *
 * One named entry of an `x-openregister-aggregations` annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * An aggregation entry under validation: its name and its raw spec.
 */
final class AggregationSpecEntry {
	/**
	 * Constructor.
	 *
	 * @param string               $name Aggregation name (for error messages).
	 * @param array<string, mixed> $spec The raw spec object.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $spec,
	) {
	}//end __construct()
}//end class
