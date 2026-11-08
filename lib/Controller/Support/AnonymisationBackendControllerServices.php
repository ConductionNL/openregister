<?php

/**
 * OpenRegister AnonymisationBackendController services
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

use OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of AnonymisationBackendController, wired by DI autowiring.
 *
 */
final class AnonymisationBackendControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession $userSession Current user session for admin gating.
	 * @param IGroupManager $groupManager Group manager for admin checks.
	 * @param AnonymisationBackendServiceInterface $service Backend state service (single source of truth).
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly AnonymisationBackendServiceInterface $service,
	) {
	}//end __construct()
}//end class
