<?php

/**
 * OpenRegister Registry Inbound Services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registry
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

namespace OCA\OpenRegister\Service\Registry;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * What an inbound registry update is checked against and written through.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-inbound-registry-update-writes-owned-properties-only
 */
final readonly class RegistryInboundServices {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objectService     Applies inbound updates through the ordinary save path.
	 * @param RegistryUpdateTargetGuard $updateTargetGuard Resolves a row's schema and checks owned properties.
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public RegistryUpdateTargetGuard $updateTargetGuard,
	) {
	}//end __construct()
}//end class
