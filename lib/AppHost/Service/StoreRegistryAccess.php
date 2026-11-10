<?php

/**
 * OpenRegister AppHost store registry access
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;

/**
 * How the store reaches a remote registry: its configured address and token,
 * and the HTTP client that calls it.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
final class StoreRegistryAccess {
	/**
	 * Bundle the registry access.
	 *
	 * @param IClientService $clientService Nextcloud HTTP client factory.
	 * @param IAppConfig     $appConfig     App config store (registry url / token / register).
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md
	 */
	public function __construct(
		public readonly IClientService $clientService,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
