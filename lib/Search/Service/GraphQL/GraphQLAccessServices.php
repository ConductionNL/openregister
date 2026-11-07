<?php

/**
 * GraphQL access services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\GraphQL;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\PropertyRbacHandler;

/**
 * The schema and property authorisation and the audit trail the GraphQL resolvers apply.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
 */
final class GraphQLAccessServices {
	/**
	 * Bundle the services.
	 *
	 * @param PermissionHandlerInterface         $permissionHandler Permission handler
	 * @param PropertyRbacHandler       $propertyRbac      Property RBAC handler
	 * @param AuditTrailMapperInterface $auditTrailMapper  Audit trail mapper
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function __construct(
		public readonly PermissionHandlerInterface $permissionHandler,
		public readonly PropertyRbacHandler $propertyRbac,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
