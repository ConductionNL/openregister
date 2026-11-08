<?php

/**
 * OpenRegister AdhocAggregationRun
 *
 * The inputs of one AggregationRunner::runAdhoc() call once the read gate has
 * passed: the register and schema, the caller's query, and the same query
 * with its filter placeholders resolved.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;

/**
 * Carries an ad-hoc aggregation through AggregationRunner.
 *
 * `query` is the caller's query as given; `resolvedQuery` differs from it
 * only in its filter, whose placeholders ($now, $currentUser, ...) are
 * resolved. The resolved query is the cache key and the DBAL request; the
 * resolved filter feeds the native SQL and the PHP filter.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AdhocAggregationRun {

	/**
	 * Constructor.
	 *
	 * @param Register         $register      The register the schema belongs to.
	 * @param Schema           $schema        The schema being aggregated.
	 * @param AggregationQuery $query         The caller's query.
	 * @param AggregationQuery $resolvedQuery The query with filter placeholders resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly AggregationQuery $query,
		public readonly AggregationQuery $resolvedQuery,
	) {
	}//end __construct()
}//end class
