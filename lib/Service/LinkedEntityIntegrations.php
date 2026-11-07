<?php

/**
 * The registries and permission check behind linked entities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The integration registry, the deep-link registry and the permission handler of the linked entity service.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class LinkedEntityIntegrations {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IntegrationRegistry     $integrationRegistry Integration registry, the authoritative type-id source
	 * @param PermissionHandlerInterface       $permissionHandler   RBAC handler for write-permission checks (SEC-CTRL-4)
	 * @param DeepLinkRegistryService $deepLinkRegistry    Deep-link registry service for cross-app deep links
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		public readonly IntegrationRegistry $integrationRegistry,
		public readonly PermissionHandlerInterface $permissionHandler,
		public readonly DeepLinkRegistryService $deepLinkRegistry,
	) {
	}//end __construct()
}//end class
