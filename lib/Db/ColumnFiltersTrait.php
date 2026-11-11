<?php

/**
 * ColumnFiltersTrait: turn a list read's column filters into conditions.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One condition per column filter of a list read.
 */
trait ColumnFiltersTrait {

	/**
	 * Add a condition per filter: `IS NOT NULL` and `IS NULL` as a null check,
	 * any other value as equality.
	 *
	 * @param IQueryBuilder $qb      The builder to add the conditions to.
	 * @param array|null    $filters Column name to value.
	 *
	 * @return void
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	private function applyColumnFilters(IQueryBuilder $qb, ?array $filters): void {
		foreach ($filters ?? [] as $filter => $value) {
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($filter));
				continue;
			}

			if ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($filter));
				continue;
			}

			$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
		}
	}//end applyColumnFilters()
}//end trait
