<?php

/**
 * OpenRegister registry subscription domain
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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Registry\RegistrySubscriptionServiceInterface;

/**
 * Services of the registry subscription API.
 *
 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
 */
final class RegistrySubscriptionDomain {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface                $schemaMapper      Resolves the object's schema.
	 * @param ObjectOperationsInterface               $objectService     Resolves the target object.
	 * @param PermissionHandlerInterface           $permissionHandler The per-object `update` guard.
	 * @param RegistrySubscriptionServiceInterface $subscriptions     Owns the subscription lifecycle.
	 *
	 * @spec openspec/changes/registry-subscriptions/specs/registry-subscriptions/spec.md#requirement-an-object-carries-a-subscription-state-a-user-can-request-or-end
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ObjectOperationsInterface $objectService,
		public readonly PermissionHandlerInterface $permissionHandler,
		public readonly RegistrySubscriptionServiceInterface $subscriptions,
	) {
	}//end __construct()
}//end class
