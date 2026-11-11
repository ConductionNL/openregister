<?php

/**
 * Several related-row filters to render as separate EXISTS clauses over one target.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use Closure;

/**
 * The filters of one query, the table they run against, the access predicate
 * per inner row, and the prefix every placeholder of the batch carries.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowClauseBatch {

	/**
	 * Bundle the batch.
	 *
	 * @param array<int, RelatedRowFilter> $filters            The parsed filters.
	 * @param RelatedRowSqlTarget          $target             The engine, objects table, outer alias and storage shape.
	 * @param Closure                      $accessPredicateFor Given the inner alias and the filter, the access predicate for rows under it.
	 * @param string                       $parameterPrefix    A prefix for this query's placeholders.
	 */
	public function __construct(
		public array $filters,
		public RelatedRowSqlTarget $target,
		public Closure $accessPredicateFor,
		public string $parameterPrefix = 'rel',
	) {
	}//end __construct()
}//end class
