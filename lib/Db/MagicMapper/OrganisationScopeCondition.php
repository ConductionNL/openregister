<?php

/**
 * The condition an organisation scope puts on a magic table's `_organisation` column.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Db\MagicMapper
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
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Renders a decided organisation scope onto a QueryBuilder query.
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
 */
final class OrganisationScopeCondition {

	/**
	 * Add the scope to the query, optionally widened by one more branch of
	 * rows that may come from any organisation.
	 *
	 * @param IQueryBuilder $qb     The query builder, over the table aliased `t`.
	 * @param array         $scope  The scope, as {@see MagicOrganizationHandler::resolveOrganizationScope()} decides it.
	 * @param mixed         $orAlso An expression whose rows join the scope, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-multi-tenancy-integration-with-scopes
	 */
	public function apply(IQueryBuilder $qb, array $scope, mixed $orAlso = null): void {
		if ($scope['mode'] === MagicOrganizationHandler::SCOPE_ALL) {
			return;
		}

		$condition = $this->condition(qb: $qb, scope: $scope);
		if ($orAlso !== null) {
			$condition = $qb->expr()->orX($condition, $orAlso);
		}

		$qb->andWhere($condition);
	}//end apply()

	/**
	 * The condition a scope other than SCOPE_ALL puts on the organisation column.
	 *
	 * @param IQueryBuilder $qb    The query builder.
	 * @param array         $scope The scope.
	 *
	 * @return mixed The condition.
	 */
	private function condition(IQueryBuilder $qb, array $scope): mixed {
		if ($scope['mode'] === MagicOrganizationHandler::SCOPE_NONE) {
			return '1 = 0';
		}

		if ($scope['mode'] === MagicOrganizationHandler::SCOPE_NULL_ONLY) {
			return $qb->expr()->isNull('t._organisation');
		}

		// Condition 1: objects belonging to the caller's active organisation(s).
		$conditions = [];
		$conditions[] = $qb->expr()->in(
			't._organisation',
			$qb->createNamedParameter($scope['uuids'], IQueryBuilder::PARAM_STR_ARRAY)
		);
		if (count($scope['uuids']) === 1) {
			array_pop($conditions);
			$conditions[] = $qb->expr()->eq(
				't._organisation',
				$qb->createNamedParameter($scope['uuids'][0])
			);
		}

		// Condition 2: objects with no organisation, for admins and for a
		// register-cascade grant (admitOrganisationless()).
		if ($scope['mode'] === MagicOrganizationHandler::SCOPE_IN_OR_NULL) {
			$conditions[] = $qb->expr()->isNull('t._organisation');
		}

		return $qb->expr()->orX(...$conditions);
	}//end condition()
}//end class
