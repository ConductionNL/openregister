<?php

/**
 * NativeOperatorTerm
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
 * One term of a filter's operator map, such as `gte: 1` in `{gte: 1, lt: 5}`.
 *
 * Built by AggregationRunner while it renders one operator map; it never leaves that class.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NativeOperatorTerm {
	/**
	 * Constructor.
	 *
	 * @param string $operator The operator, such as `in`, `isnull` or `gte`
	 * @param mixed  $operand  The value the operator compares with
	 */
	public function __construct(
		public readonly string $operator,
		public readonly mixed $operand,
	) {
	}//end __construct()
}//end class
