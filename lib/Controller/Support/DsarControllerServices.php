<?php

/**
 * OpenRegister DsarController services
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

use OCA\OpenRegister\Service\AvgComplianceService;
use OCA\OpenRegister\Service\DsarService;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of DsarController, wired by DI autowiring.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class DsarControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DsarService $dsarService DSAR composition service.
	 * @param AvgComplianceService $complianceService AVG compliance auditor.
	 * @param IUserSession $userSession Current user (admin gate).
	 * @param IGroupManager $groupManager Group manager (admin gate).
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public readonly DsarService $dsarService,
		public readonly AvgComplianceService $complianceService,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
