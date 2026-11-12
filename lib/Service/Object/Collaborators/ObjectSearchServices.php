<?php

/**
 * OpenRegister ObjectService search services: queries, facets and their performance tuning
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;

/**
 * ObjectService search services: queries, facets and their performance tuning.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectSearchServices {
	/**
	 * Bundle the services.
	 *
	 * @param SearchQueryHandler             $searchQueryHandler Handler for search query operations.
	 * @param QueryHandler                   $queryHandler       Handler for query operations.
	 * @param FacetHandler                   $facetHandler       Handler for facet operations.
	 * @param PerformanceOptimizationHandler $perfOptHandler     Handler for performance optimization.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly SearchQueryHandler $searchQueryHandler,
		public readonly QueryHandler $queryHandler,
		public readonly FacetHandler $facetHandler,
		public readonly PerformanceOptimizationHandler $perfOptHandler,
	) {
	}//end __construct()
}//end class
