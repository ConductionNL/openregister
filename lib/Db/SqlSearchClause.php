<?php

/**
 * SqlSearchClause: raw SQL search fragments, OR'd together, and the parameters they bind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/source-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * SQL fragments joined with OR into one AND-ed group, plus their named parameters.
 *
 * @spec openspec/specs/source-management/spec.md
 */
final class SqlSearchClause {

	/**
	 * Constructor.
	 *
	 * @param array|null $conditions SQL fragments, joined with OR; none adds nothing.
	 * @param array|null $params     Named parameters the fragments bind.
	 */
	public function __construct(
		public readonly ?array $conditions=[],
		public readonly ?array $params=[],
	) {
	}//end __construct()

	/**
	 * AND the OR-group of fragments into the query and bind the parameters.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/source-management/spec.md
	 */
	public function applyTo(IQueryBuilder $qb): void {
		if (empty($this->conditions) === true) {
			return;
		}

		$qb->andWhere('(' . implode(' OR ', $this->conditions) . ')');
		foreach ($this->params ?? [] as $param => $value) {
			$qb->setParameter($param, $value);
		}
	}//end applyTo()
}//end class
