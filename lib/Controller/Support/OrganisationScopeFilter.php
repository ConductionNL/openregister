<?php

/**
 * The organisations a caller may see, as a filter on an entity query.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Accessible organisation uuids, or null for an administrator who sees every organisation.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class OrganisationScopeFilter {

	/**
	 * Constructor.
	 *
	 * @param array<int, string>|null $uuids Accessible organisation UUIDs, null for admins.
	 */
	public function __construct(
		public readonly ?array $uuids,
	) {
	}//end __construct()

	/**
	 * Constrain a query to the accessible organisations: `WHERE <column> IN (:uuids)`.
	 *
	 * For admins (a null scope) this is a no-op (#1825).
	 *
	 * @param IQueryBuilder $qb     Query builder to constrain.
	 * @param string        $column Column holding the org UUID.
	 *
	 * @return void
	 */
	public function narrow(IQueryBuilder $qb, string $column): void {
		if ($this->uuids === null) {
			return;
		}

		$qb->andWhere(
			$qb->expr()->in($column, $qb->createNamedParameter($this->uuids, IQueryBuilder::PARAM_STR_ARRAY))
		);
	}//end narrow()
}//end class
