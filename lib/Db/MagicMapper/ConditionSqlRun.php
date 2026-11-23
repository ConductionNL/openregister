<?php

/**
 * ConditionSqlRun
 *
 * One pass of the raw-SQL condition builder.
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

/**
 * The state the raw-SQL condition helpers of MagicSearchHandler share while
 * they build the WHERE conditions of one table: the request query, the table
 * read, the connection that quotes values, and whether the platform is
 * PostgreSQL (ADR-011 Rule 3, run object). It never leaves MagicSearchHandler.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */
final class ConditionSqlRun
{

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query      The request query.
	 * @param MagicReadTable       $table      The table the conditions are for.
	 * @param object               $connection The connection that quotes values.
	 * @param bool                 $isPostgres Whether the platform is PostgreSQL.
	 */
	public function __construct(
		public readonly array $query,
		public readonly MagicReadTable $table,
		public readonly object $connection,
		public readonly bool $isPostgres,
	) {
	}//end __construct()

	/**
	 * The schema of the table the conditions are for.
	 *
	 * @return Schema
	 */
	public function schema(): Schema {
		return $this->table->schema;
	}//end schema()
}//end class
