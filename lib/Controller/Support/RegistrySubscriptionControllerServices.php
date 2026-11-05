<?php

/**
 * OpenRegister registry subscription controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the registry subscription API.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
final class RegistrySubscriptionControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegistrySubscriptionDomain   $domain   The domain bundle.
	 * @param RegistrySubscriptionPlatform $platform The platform bundle.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function __construct(
		public readonly RegistrySubscriptionDomain $domain,
		public readonly RegistrySubscriptionPlatform $platform,
	) {
	}//end __construct()
}//end class
