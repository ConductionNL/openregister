<?php

/**
 * QueryPage: the optional limit and offset of a list read.
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
 * A page of a list read; a null bound is left off the query.
 *
 * @spec exclude shared mapper plumbing, not a capability of its own
 */
final class QueryPage {

	/**
	 * Constructor.
	 *
	 * @param int|null $limit  Maximum number of rows, or null for no maximum.
	 * @param int|null $offset Rows to skip, or null for none.
	 */
	public function __construct(
		private readonly ?int $limit,
		private readonly ?int $offset,
	) {
	}//end __construct()

	/**
	 * Set the bounds that are given on a query.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function applyTo(IQueryBuilder $qb): void {
		if ($this->limit !== null) {
			$qb->setMaxResults($this->limit);
		}

		if ($this->offset !== null) {
			$qb->setFirstResult($this->offset);
		}
	}//end applyTo()
}//end class
