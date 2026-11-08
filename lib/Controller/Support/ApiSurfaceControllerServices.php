<?php

/**
 * OpenRegister ApiSurfaceController services
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

use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService;
use OCA\OpenRegister\Service\ApiVersion\ApiContractService;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue;

/**
 * The collaborators of ApiSurfaceController, wired by DI autowiring.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final class ApiSurfaceControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ApiCapabilitiesService $capabilities Builds the capabilities answer.
	 * @param ApiVersionCatalogue $catalogue The declared contract versions.
	 * @param ApiContractService $contracts Builds one description per version.
	 * @param ControllerSession $session Session, group manager and logger.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function __construct(
		public readonly ApiCapabilitiesService $capabilities,
		public readonly ApiVersionCatalogue $catalogue,
		public readonly ApiContractService $contracts,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
