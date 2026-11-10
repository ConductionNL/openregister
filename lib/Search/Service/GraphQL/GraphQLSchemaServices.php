<?php

/**
 * GraphQL schema services
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

use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;

/**
 * What builds the GraphQL schema, prices a query against it and formats its errors.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */
final class GraphQLSchemaServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaGenerator         $schemaGenerator    Schema generator
	 * @param GraphQLResolver         $resolver           GraphQL resolver
	 * @param QueryComplexityAnalyzer $complexityAnalyzer Complexity analyzer
	 * @param GraphQLErrorFormatter   $errorFormatter     Error formatter
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function __construct(
		public readonly SchemaGenerator $schemaGenerator,
		public readonly GraphQLResolver $resolver,
		public readonly QueryComplexityAnalyzer $complexityAnalyzer,
		public readonly GraphQLErrorFormatter $errorFormatter,
	) {
	}//end __construct()
}//end class
