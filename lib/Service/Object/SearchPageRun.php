<?php

/**
 * OpenRegister SearchPageRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */

namespace OCA\OpenRegister\Service\Object;

use ArrayObject;

/**
 * The state one paginated database search carries between its steps in QueryHandler.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */
final class SearchPageRun {

	/**
	 * The rbac, multitenancy, ids, uses, narrowedToNothing and activeOrgUuid of the search, with its limit, offset and page.
	 *
	 * @var array<string, mixed>
	 */
	public array $scope = [];

	/**
	 * The query the facets are computed over: the search query without paging.
	 *
	 * @var array<string, mixed>
	 */
	public array $countQuery = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>       $query   The search query.
	 * @param ArrayObject<string, mixed> $metrics Collects the timings of the search, keyed by step.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function __construct(
		public readonly array $query,
		public ArrayObject $metrics,
	) {
	}//end __construct()
}//end class
