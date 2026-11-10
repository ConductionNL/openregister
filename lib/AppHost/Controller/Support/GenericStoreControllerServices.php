<?php

/**
 * OpenRegister generic store controller services
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

/**
 * The service bundles of the generic store API.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class GenericStoreControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param GenericStoreSources  $sources  Where a store is declared and searched.
	 * @param GenericStoreInstall  $install  What installs an item and gates the action.
	 * @param GenericStorePlatform $platform Session, admin check and logger.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public function __construct(
		public readonly GenericStoreSources $sources,
		public readonly GenericStoreInstall $install,
		public readonly GenericStorePlatform $platform,
	) {
	}//end __construct()
}//end class
