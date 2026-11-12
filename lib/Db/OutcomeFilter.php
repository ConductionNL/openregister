<?php

/**
 * OutcomeFilter: the optional outcome and page of a per-item outcome list.
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
 * Narrows a list of per-item outcome rows to one outcome and one page.
 *
 * @spec exclude shared mapper plumbing, not a capability of its own
 */
final class OutcomeFilter {

	/**
	 * Constructor.
	 *
	 * @param string|null    $outcome The outcome to keep, or null for every outcome.
	 * @param QueryPage|null $page    The page, or null for every row.
	 */
	public function __construct(
		private readonly ?string $outcome = null,
		private readonly ?QueryPage $page = null,
	) {
	}//end __construct()

	/**
	 * Add the outcome condition and the page bounds to a query.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function applyTo(IQueryBuilder $qb): void {
		if ($this->outcome !== null) {
			$qb->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter($this->outcome)));
		}

		$this->page?->applyTo(qb: $qb);
	}//end applyTo()
}//end class
