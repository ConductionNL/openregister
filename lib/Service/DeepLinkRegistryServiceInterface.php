<?php

/**
 * DeepLinkRegistryServiceInterface: what callers outside the Service component use of DeepLinkRegistryService.
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

use OCP\EventDispatcher\IEventDispatcher;

/**
 * The deep-link registration and resolution other components call on DeepLinkRegistryService.
 */
interface DeepLinkRegistryServiceInterface {

	/**
	 * Register a deep link pattern for a (register, schema) combination.
	 *
	 * @param string $appId The consuming app ID (e.g., "procest")
	 * @param string $registerSlug The register slug
	 * @param string $schemaSlug The schema slug
	 * @param string $urlTemplate URL template with placeholders (e.g., "/apps/procest/#/cases/{uuid}")
	 * @param string $icon Optional icon identifier (defaults to "icon-{appId}")
	 * @param string|null $displayName Optional human-readable label for the app's
	 *                                 unified-search results (defaults to null → app id)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md#requirement-notification-deep-links-shall-use-the-deep-link-registry
	 * @spec openspec/specs/deep-link-registry/spec.md
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function register(
		string $appId,
		string $registerSlug,
		string $schemaSlug,
		string $urlTemplate,
		string $icon = '',
		?string $displayName = null,
	): void;

	/**
	 * Ask the apps for their deep links, once per process.
	 *
	 * OpenRegister's boot() calls this with its dispatcher. A process that
	 * never boots OpenRegister reaches it through resolve() instead, so the
	 * registry is filled in a background job the same way as in a request.
	 *
	 * @param IEventDispatcher|null $dispatcher The dispatcher, or null to take it from the container.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/deep-link-registry/spec.md#requirement-the-registry-must-fill-itself-in-a-process-that-never-booted-openregister
	 */
	public function requestRegistrations(?IEventDispatcher $dispatcher = null): void;

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
