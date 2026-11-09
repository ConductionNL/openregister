<?php

/**
 * ObjectSearchResultFormatterInterface — turns one rendered object row into a
 * Nextcloud unified-search result entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Search\SearchResultEntry;

/**
 * What the search providers (unified search and the schema-scoped AppHost
 * providers) use of ObjectSearchResultFormatter.
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */
interface ObjectSearchResultFormatterInterface {

	/**
	 * Build a `SearchResultEntry` for one rendered search result row.
	 *
	 * @param array|ObjectEntity $result The rendered object data (already RBAC-filtered),
	 *                                   or the entity itself (normalised internally)
	 * @param string|null        $term   The search term (null/empty for filter-only browse)
	 *
	 * @return SearchResultEntry The formatted result entry
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function format(array|ObjectEntity $result, ?string $term): SearchResultEntry;
}//end interface
