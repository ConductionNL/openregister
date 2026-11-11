<?php

/**
 * DeepLinkResolverInterface: resolving the deep link and icon registered for a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * Looks up the URL and icon an app registered for a register/schema pair.
 */
interface DeepLinkResolverInterface {

	/**
	 * Get the icon for a registered deep link, or null if no registration.
	 *
	 * @param int $registerId The register database ID
	 * @param int $schemaId The schema database ID
	 *
	 * @return string|null The icon identifier, or null
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md#requirement-deep-link-registry-shall-resolve-urls-for-unified-search-results
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function resolveIcon(int $registerId, int $schemaId): ?string;

	/**
	 * Resolve a URL for a search result, falling back to null if no registration exists.
	 *
	 * @param int $registerId The register database ID
	 * @param int $schemaId The schema database ID
	 * @param array $objectData The object data from search results
	 * @param array $contactContext Optional contact context for placeholder resolution
	 *                              Supports: contactId, contactEmail, contactName
	 *
	 * @return string|null The resolved URL, or null to use default
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md#requirement-deep-link-registry-shall-resolve-urls-for-unified-search-results
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function resolveUrl(
		int $registerId,
		int $schemaId,
		array $objectData,
		array $contactContext = [],
	): ?string;
}//end interface
