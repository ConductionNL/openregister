<?php

/**
 * GraphQL Request
 *
 * One GraphQL operation as a client sends it: the query, its variables and the operation to run.
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
 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
 */

namespace OCA\OpenRegister\Search\Service\GraphQL;

/**
 * A GraphQL request: the query string, its variables and the operation name.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
 */
final class GraphQLRequest {
	/**
	 * Constructor.
	 *
	 * @param string                    $query         The GraphQL query string
	 * @param array<string, mixed>|null $variables     Query variables
	 * @param string|null               $operationName Operation name
	 */
	public function __construct(
		public readonly string $query,
		public readonly ?array $variables = null,
		public readonly ?string $operationName = null,
	) {
	}//end __construct()
}//end class
