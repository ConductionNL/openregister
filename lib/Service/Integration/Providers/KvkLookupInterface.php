<?php

/**
 * KvkLookupInterface: what callers outside the Service component use of KvkProvider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
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

namespace OCA\OpenRegister\Service\Integration\Providers;

/**
 * Looks up and searches companies in the KvK Handelsregister.
 *
 * @spec openspec/specs/integration-company-lookup/spec.md
 */
interface KvkLookupInterface {

	/**
	 * Look up one company by its KvK number.
	 *
	 * @param string $kvkNumber The 8-digit KvK number.
	 *
	 * @return array<string,mixed> `{ results, total }` on success, or an error envelope.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function lookupByKvkNumber(string $kvkNumber): array;

	/**
	 * Search companies by name or text.
	 *
	 * @param CompanySearch $search The text, paging and extra criteria.
	 *
	 * @return array<string,mixed> `{ results, total, limit, page }` on success, or an error envelope.
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function searchCompanies(CompanySearch $search): array;
}//end interface
