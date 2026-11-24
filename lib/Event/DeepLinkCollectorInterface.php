<?php
/**
 * DeepLinkCollectorInterface: where the deep-link registration event adds an app's links.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Dto\DeepLinkRegistration;

/**
 * Collects the deep-link patterns apps register while the registration event runs.
 *
 * DeepLinkRegistrationEvent hands its listeners this interface. The Core deep-link
 * registry extends it with resolution, so the registry the event carries is the
 * same object as before.
 *
 * @spec openspec/specs/deep-link-registry/spec.md
 */
interface DeepLinkCollectorInterface {

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
}//end interface
