<?php

/**
 * OpenRegister GraphQLResolverRights
 *
 * The schema-level and property-level RBAC collaborators of the GraphQL resolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\GraphQL;

use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;

/**
 * The schema-level and property-level RBAC collaborators of the GraphQL resolver.
 *
 * Groups collaborators of {@see GraphQLResolver} so its constructor stays small;
 * the container autowires it.
 */
final class GraphQLResolverRights {

	/**
	 * Bundle the collaborators.
	 *
	 * @param PermissionHandlerInterface $permissionHandler Permission handler
	 * @param PropertyRbacHandler        $propertyRbac      Property RBAC handler
	 */
	public function __construct(
		public readonly PermissionHandlerInterface $permissionHandler,
		public readonly PropertyRbacHandler $propertyRbac,
	) {
	}//end __construct()
}//end class
