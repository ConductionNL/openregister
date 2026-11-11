<?php

/**
 * OrganisationlessAdmission: widens an organisation scope by the rows with no organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The register-cascade widening of an organisation scope.
 *
 * Moved out of MagicOrganizationHandler::admitOrganisationless(), which now
 * delegates here, so callers outside lib/Db reach the rule without naming
 * the handler. Holds no state; the scope it widens is its only input.
 *
 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
 */
final class OrganisationlessAdmission {

	/**
	 * Widen an organisation scope by the rows with NO organisation.
	 *
	 * Used when the caller's RBAC grant comes from the register cascade (see
	 * MagicRbacHandler::admitsOrganisationlessRowsThroughCascade()): org-less
	 * rows belong to no tenant, so the grant reaches them, while the rows of
	 * other organisations stay outside the scope. Only the two modes that
	 * exclude org-less rows change; every other mode, an unknown one included,
	 * is returned untouched so each renderer keeps its own fail-closed answer.
	 *
	 * @param array{mode: string, uuids: array<int, string>} $scope The decided scope.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The widened scope.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public static function admit(array $scope): array {
		$mode = ($scope['mode'] ?? null);

		if ($mode === OrganisationScopeResolverInterface::SCOPE_IN) {
			$scope['mode'] = OrganisationScopeResolverInterface::SCOPE_IN_OR_NULL;
		}

		if ($mode === OrganisationScopeResolverInterface::SCOPE_NONE) {
			$scope['mode'] = OrganisationScopeResolverInterface::SCOPE_NULL_ONLY;
			$scope['uuids'] = [];
		}

		return $scope;
	}//end admit()
}//end class
