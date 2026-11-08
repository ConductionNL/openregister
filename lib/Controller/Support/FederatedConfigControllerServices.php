<?php

/**
 * OpenRegister FederatedConfigController services
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

use OCA\OpenRegister\Service\Config\FederatedConfigAccess;
use OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface;
use OCP\IConfig;

/**
 * The collaborators of FederatedConfigController, wired by DI autowiring.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
final class FederatedConfigControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FederatedConfigServiceInterface $service The federation engine.
	 * @param FederatedConfigAccess $access Per-org publish/install gating.
	 * @param ControllerSession $session Session, group manager and logger.
	 * @param IConfig $config Reads the user's chosen store credential.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function __construct(
		public readonly FederatedConfigServiceInterface $service,
		public readonly FederatedConfigAccess $access,
		public readonly ControllerSession $session,
		public readonly IConfig $config,
	) {
	}//end __construct()
}//end class
