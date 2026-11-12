<?php

/**
 * ObjectFieldFilter
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One object field being filtered on the QueryBuilder search path.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ObjectFieldFilter {

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb           The query builder, for expressions and parameter binding
	 * @param string        $columnName   The sanitized column name
	 * @param string        $propertyType The property's JSON schema type
	 * @param bool          $isPostgres   Whether the active platform is PostgreSQL
	 */
	public function __construct(
		public readonly IQueryBuilder $qb,
		public readonly string $columnName,
		public readonly string $propertyType,
		public readonly bool $isPostgres,
	) {
	}//end __construct()

	/**
	 * The column on the search's `t` alias.
	 *
	 * @return string The column reference.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function columnRef(): string {
		return "t.{$this->columnName}";
	}//end columnRef()
}//end class
