<?php

/**
 * One page of a schema-scoped unified search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Search;

/**
 * The term, page size and offset a search asks for.
 *
 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
 */
final class SchemaSearchPage {
	/**
	 * Constructor.
	 *
	 * @param string $term   The search term.
	 * @param int    $limit  The page size.
	 * @param int    $offset The offset of the page.
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	public function __construct(
		public readonly string $term,
		public readonly int $limit,
		public readonly int $offset,
	) {
	}//end __construct()
}//end class
