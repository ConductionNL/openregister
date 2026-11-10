<?php

/**
 * GraphQL data services
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The object, register and schema stores the GraphQL resolvers read and write.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */
final class GraphQLDataServices {
	/**
	 * Register mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService Object service
	 * @param SchemaCatalogInterface    $catalog       The register and schema mappers.
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		SchemaCatalogInterface $catalog,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
	}//end __construct()
}//end class
