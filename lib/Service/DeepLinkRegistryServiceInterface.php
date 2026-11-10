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
interface DeepLinkRegistryServiceInterface extends DeepLinkResolverInterface {

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

}//end interface
