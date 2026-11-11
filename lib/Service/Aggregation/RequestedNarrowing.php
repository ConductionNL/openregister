<?php

/**
 * RequestedNarrowing
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Caller-supplied constraints that may only narrow a declared aggregation filter,
 * and the aggregation they were asked for (named in the debug log of a refused key).
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class RequestedNarrowing {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $constraints Caller-supplied constraints.
	 * @param string               $aggregation Aggregation name, for the debug log.
	 */
	public function __construct(
		public readonly array $constraints,
		public readonly string $aggregation,
	) {
	}//end __construct()
}//end class
