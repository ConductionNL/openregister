<?php

/**
 * The related row one existence clause consults: its alias, its access predicate and its placeholder prefix.
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

/**
 * The inner row of one `EXISTS` clause.
 *
 * 🔴 THE ACCESS PREDICATE TRAVELS WITH THE ALIAS IT RESTRICTS. It is SQL over
 * that alias, so the two belong together. It is not checked here:
 * `RelatedRowExistsClause::render()` refuses a blank one before it writes any
 * SQL, which keeps the refusal in one place.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowInnerRow {

	/**
	 * Bundle the inner row.
	 *
	 * @param string $alias           The alias to give the related row.
	 * @param string $accessPredicate SQL restricting the related rows to ones the caller may read.
	 * @param string $parameterPrefix A prefix making this clause's placeholders unique.
	 */
	public function __construct(
		public string $alias,
		public string $accessPredicate,
		public string $parameterPrefix,
	) {
	}//end __construct()
}//end class
