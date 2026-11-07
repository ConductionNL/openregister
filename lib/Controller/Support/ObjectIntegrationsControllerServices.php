<?php

/**
 * OpenRegister object integrations controller services
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

use Psr\Log\LoggerInterface;

/**
 * The service bundles of the per-object integrations API.
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
 */
final class ObjectIntegrationsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectIntegrationsDomain $domain The registry and the object and schema services.
	 * @param ObjectIntegrationsAccess $access The timeline visibility filter and the update right.
	 * @param LoggerInterface          $logger Logger.
	 *
	 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-19
	 */
	public function __construct(
		public readonly ObjectIntegrationsDomain $domain,
		public readonly ObjectIntegrationsAccess $access,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
