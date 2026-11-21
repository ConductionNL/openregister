<?php

/**
 * OpenRegister QueryHandlers
 *
 * The handlers an object query delegates to: search, facets, performance and content search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * The handlers an object query delegates to: search, facets, performance and content search.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final readonly class QueryHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SearchQueryHandler             $searchQueryHandler   Search handler.
	 * @param FacetHandler                   $facetHandler         Facet handler.
	 * @param PerformanceOptimizationHandler $performanceHandler   Performance handler.
	 * @param ContentSearchHandler           $contentSearchHandler Opt-in _content_search chunk fan-out handler.
	 */
	public function __construct(
		public SearchQueryHandler $searchQueryHandler,
		public FacetHandler $facetHandler,
		public PerformanceOptimizationHandler $performanceHandler,
		public ContentSearchHandler $contentSearchHandler,
	) {
	}//end __construct()
}//end class
