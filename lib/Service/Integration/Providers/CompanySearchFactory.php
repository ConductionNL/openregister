<?php

/**
 * CompanySearchFactory: builds the company search a lookup takes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Builds a CompanySearch, so callers outside the providers never construct one.
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */
final class CompanySearchFactory implements CompanySearchFactoryInterface {
	/**
	 * A company search as a caller asks it, without criteria.
	 *
	 * @param string $query The free text.
	 * @param int    $limit The page size asked for.
	 * @param int    $page  The 1-based page asked for.
	 *
	 * @return CompanySearch The search.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function search(string $query, int $limit, int $page): CompanySearch {
		return new CompanySearch(query: $query, limit: $limit, page: $page);
	}//end search()
}//end class
