<?php

/**
 * NativeAggregationStatement
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
 * One native aggregation statement being run: the built query, its aggregate
 * expression and whether a running total was asked for (REQ-AGG-103).
 *
 * Built by AggregationRunner for one native run; it never leaves that class.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NativeAggregationStatement {
	/**
	 * Constructor.
	 *
	 * @param NativeAggregationQuery $query      The built query
	 * @param string                 $aggSql     The aggregate expression
	 * @param bool                   $cumulative Whether a running total was asked for
	 */
	public function __construct(
		public readonly NativeAggregationQuery $query,
		public readonly string $aggSql,
		public readonly bool $cumulative,
	) {
	}//end __construct()

	/**
	 * Whether the SQL itself computes the running total (a Postgres window column).
	 *
	 * MySQL and SQLite compute it in a PHP post-pass instead.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function windowedTotal(): bool {
		return $this->cumulative === true && $this->query->isPostgres() === true;
	}//end windowedTotal()
}//end class
