<?php

/**
 * OpenRegister schema services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\SchemaExplorationInterface;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use OCA\OpenRegister\Service\Schemas\FacetResultCacheClearerInterface;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandlerInterface;

/**
 * Schema caches, exploration and versioning.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCacheHandlerInterface         $schemaCacheService The schema cache.
	 * @param FacetResultCacheClearerInterface    $facetResults       Clears the facet result caches.
	 * @param SchemaExplorationInterface $schemaService      The schema exploration service.
	 * @param SchemaVersioningService    $versioning         The schema versioning service.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly SchemaCacheHandlerInterface $schemaCacheService,
		public readonly FacetResultCacheClearerInterface $facetResults,
		public readonly SchemaExplorationInterface $schemaService,
		public readonly SchemaVersioningService $versioning,
	) {
	}//end __construct()
}//end class
