<?php

/**
 * OpenRegister generic store install
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller\Support
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

namespace OCA\OpenRegister\AppHost\Controller\Support;

use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;

/**
 * What installs a store item, and the action resolver that gates install and publish.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class GenericStoreInstall {
	/**
	 * Bundle the services.
	 *
	 * @param GenericStoreInstaller $installer        Declarative component install.
	 * @param StoreActionAuthorizer $actionAuthorizer ADR-023 action resolver.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public function __construct(
		public readonly GenericStoreInstaller $installer,
		public readonly StoreActionAuthorizer $actionAuthorizer,
	) {
	}//end __construct()
}//end class
