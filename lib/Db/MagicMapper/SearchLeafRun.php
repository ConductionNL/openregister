<?php

/**
 * SearchLeafRun
 *
 * One literal term of a parsed search on the QueryBuilder path.
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
 * The state the search-leaf helpers of MagicSearchHandler share while they
 * build the SQL of one literal term: the query builder that binds values, the
 * leaf and the bound parameter of its pattern (ADR-011 Rule 3, run object). It
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
final class SearchLeafRun
{

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb           The query builder that binds values.
	 * @param SearchLeaf    $leaf         The term, its literal and its scope.
	 * @param string        $patternParam The bound parameter of the term's pattern.
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly SearchLeaf $leaf,
		public readonly string $patternParam,
	) {
	}//end __construct()
}//end class
