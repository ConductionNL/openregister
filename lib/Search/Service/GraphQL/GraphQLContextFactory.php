<?php

/**
 * GraphQL resolver context factory
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

use OCP\IRequest;

/**
 * Builds the context every GraphQL resolver receives.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
 */
final class GraphQLContextFactory {
	/**
	 * Constructor.
	 *
	 * @param GraphQLDataServices   $data    The stores the resolvers use
	 * @param GraphQLAccessServices $access  The authorisation and audit the resolvers apply
	 * @param IRequest              $request Current request
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function __construct(
		private readonly GraphQLDataServices $data,
		private readonly GraphQLAccessServices $access,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Create the resolver context passed to all resolvers.
	 *
	 * @param SchemaGenerator $schemaGenerator The schema generator
	 * @param string|null     $operationName   The operation name
	 *
	 * @return array<string, mixed> The context
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-graphql-must-enforce-schema-level-rbac-via-permissionhandler
	 */
	public function create(SchemaGenerator $schemaGenerator, ?string $operationName): array {
		return [
			'objectService' => $this->data->objectService,
			'permissionHandler' => $this->access->permissionHandler,
			'propertyRbac' => $this->access->propertyRbac,
			'auditTrailMapper' => $this->access->auditTrailMapper,
			'registerMapper' => $this->data->registerMapper,
			'schemaMapper' => $this->data->schemaMapper,
			'schemaGenerator' => $schemaGenerator,
			'operationName' => $operationName,
			'request' => $this->request,
			'errors' => [],
		];
	}//end create()
}//end class
