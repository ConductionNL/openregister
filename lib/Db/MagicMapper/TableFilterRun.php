<?php

/**
 * TableFilterRun
 *
 * One pass of the filter pipeline that narrows a magic-table query.
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

use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The state the filter helpers of MagicSearchHandler share while they narrow
 * one query: the query builder they add predicates to, the request query they
 * read the filters from, and the table being read (ADR-011 Rule 3, run
 * object). It never leaves MagicSearchHandler.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class TableFilterRun
{

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder        $qb    The query being narrowed.
	 * @param array<string, mixed> $query The request query.
	 * @param MagicReadTable       $table The table being read.
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly array $query,
		public readonly MagicReadTable $table,
	) {
	}//end __construct()

	/**
	 * The schema of the table being read.
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->table->schema;
	}//end schema()
}//end class
