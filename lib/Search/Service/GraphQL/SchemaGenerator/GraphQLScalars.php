<?php

/**
 * The custom scalar types of a generated GraphQL schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Search\Service\GraphQL\SchemaGenerator;

use GraphQL\Type\Definition\Type;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\DateTimeType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\EmailType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\JsonType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\UriType;
use OCA\OpenRegister\Search\Service\GraphQL\Scalar\UuidType;

/**
 * Creates one instance of each custom scalar for a schema.
 */
class GraphQLScalars {

	/**
	 * Fresh scalar instances by name.
	 *
	 * @return array<string, Type>
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function all(): array {
		return [
			'DateTime' => new DateTimeType(),
			'UUID' => new UuidType(),
			'Email' => new EmailType(),
			'URI' => new UriType(),
			'JSON' => new JsonType(),
		];
	}//end all()
}//end class
