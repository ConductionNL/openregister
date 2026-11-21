<?php

/**
 * OpenRegister RegistryIdentity
 *
 * The registry and the identity value one subscription is keyed on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
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

namespace OCA\OpenRegister\Service\Registry;

/**
 * The registry (`brp`, `kvk`, ...) and the identity value (a BSN, a KvK
 * number) a subscription is keyed on and an update is addressed to.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
final class RegistryIdentity {

	/**
	 * Name the identity.
	 *
	 * @param string $registry      The registry id.
	 * @param string $identityValue The identity value within that registry.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function __construct(
		public readonly string $registry,
		public readonly string $identityValue,
	) {
	}//end __construct()
}//end class
