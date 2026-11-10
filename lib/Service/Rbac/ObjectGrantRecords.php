<?php

/**
 * What an object's access is recorded and resolved by: the authorization writer, the scope vocabulary,
 * the grant resolver and the inherited-grant lister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The authorization collaborators of ObjectSharingService, wired by DI autowiring.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class ObjectGrantRecords {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectAuthorizationWriter $authorizationWriter The one writer of the stored authorization block.
	 * @param ObjectScopeResolverInterface $scopeResolver   The scope vocabulary.
	 * @param ObjectGrantResolver       $grantResolver       The grant resolver, to drop its per-request memo.
	 * @param InheritedGrantLister      $inheritedGrants     Lists the grants an ancestor's share confers.
	 */
	public function __construct(
		public readonly ObjectAuthorizationWriter $authorizationWriter,
		public readonly ObjectScopeResolverInterface $scopeResolver,
		public readonly ObjectGrantResolver $grantResolver,
		public readonly InheritedGrantLister $inheritedGrants,
	) {
	}//end __construct()
}//end class
