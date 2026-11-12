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
use OCA\OpenRegister\Dto\DeepLinkRegistration;

/**
 * The deep-link registration and resolution other components call on DeepLinkRegistryService.
 */
interface DeepLinkRegistryServiceInterface extends DeepLinkResolverInterface {

	/**
	 * Add a deep link registration for its (register, schema) combination.
	 *
	 * @param DeepLinkRegistration $registration The consuming app, the register and schema slugs, the URL template with
	 *                                           placeholders (e.g. "/apps/procest/#/cases/{uuid}"), the optional icon
	 *                                           (defaults to "icon-{appId}") and the optional unified-search label.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/deep-link-registry/spec.md#requirement-notification-deep-links-shall-use-the-deep-link-registry
	 * @spec openspec/specs/deep-link-registry/spec.md
	 */
	public function add(DeepLinkRegistration $registration): void;

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
