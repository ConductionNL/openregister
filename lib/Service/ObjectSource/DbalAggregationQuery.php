<?php

/**
 * DbalAggregationQuery — an aggregation bound to its connection: the table
 * expression and the aggregate SELECT expression it runs with.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use Doctrine\DBAL\Connection;

/**
 * The connection, table expression, aggregate expression and aggregation of one aggregate query.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalAggregationQuery {

	/**
	 * Constructor.
	 *
	 * @param Connection      $connection  The DBAL connection.
	 * @param string          $table       The quoted table (or derived query) expression.
	 * @param string          $aggExpr     The aggregate SELECT expression.
	 * @param DbalAggregation $aggregation The metric, filter and column allowlist.
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function __construct(
		public readonly Connection $connection,
		public readonly string $table,
		public readonly string $aggExpr,
		public readonly DbalAggregation $aggregation,
	) {
	}//end __construct()
}//end class
