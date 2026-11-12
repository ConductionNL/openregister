<?php

/**
 * SearchTermRun
 *
 * One full-text search on the QueryBuilder path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The state the full-text search helpers of MagicSearchHandler share while
 * they narrow one query: the filter pass (query builder and the query that
 * carries the term) and the leaf scope (schema, platform, fuzzy flag)
 * (ADR-011 Rule 3, run object). It never leaves MagicSearchHandler.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchTermRun
{

	/**
	 * Constructor.
	 *
	 * @param TableFilterRun  $filter The filter pass: query builder, query and table.
	 * @param SearchLeafScope $scope  The schema, platform and fuzzy flag of the search.
	 */
	public function __construct(
		public readonly TableFilterRun $filter,
		public readonly SearchLeafScope $scope,
	) {
	}//end __construct()

	/**
	 * The query builder the search narrows.
	 *
	 * @return IQueryBuilder The query builder.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function qb(): IQueryBuilder {
		return $this->filter->qb;
	}//end qb()

	/**
	 * The search term, trimmed.
	 *
	 * @return string The term.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function term(): string {
		return trim($this->filter->query['_search']);
	}//end term()
}//end class
