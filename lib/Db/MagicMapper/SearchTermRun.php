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
 * they narrow one query: the query builder, the trimmed search term and the
 * leaf scope (schema, platform, fuzzy flag) (ADR-011 Rule 3, run object). It
 * never leaves MagicSearchHandler.
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
	 * @param IQueryBuilder   $qb    The query builder the search narrows.
	 * @param string          $term  The trimmed search term.
	 * @param SearchLeafScope $scope The schema, platform and fuzzy flag of the search.
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly string $term,
		public readonly SearchLeafScope $scope,
	) {
	}//end __construct()
}//end class
