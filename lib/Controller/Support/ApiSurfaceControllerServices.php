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

use OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesServiceInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiContractServiceInterface;
use OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogueInterface;

/**
 * The collaborators of ApiSurfaceController, wired by DI autowiring.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final class ApiSurfaceControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ApiCapabilitiesServiceInterface $capabilities Builds the capabilities answer.
	 * @param ApiVersionCatalogueInterface $catalogue The declared contract versions.
	 * @param ApiContractServiceInterface $contracts Builds one description per version.
	 * @param ControllerSession $session Session, group manager and logger.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public function __construct(
		public readonly ApiCapabilitiesServiceInterface $capabilities,
		public readonly ApiVersionCatalogueInterface $catalogue,
		public readonly ApiContractServiceInterface $contracts,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
