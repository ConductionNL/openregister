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
	 * Current user session for admin gating.
	 *
	 * @var IUserSession
	 */
	public readonly IUserSession $userSession;

	/**
	 * Group manager for admin checks.
	 *
	 * @var IGroupManager
	 */
	public readonly IGroupManager $groupManager;

	/**
	 * Bundle the services.
	 *
	 * @param SessionPrincipal $principal The session and group manager, kept as userSession and groupManager.
	 * @param AnonymisationBackendServiceInterface $service Backend state service (single source of truth).
	 */
	public function __construct(
		SessionPrincipal $principal,
		public readonly AnonymisationBackendServiceInterface $service,
	) {
		$this->userSession  = $principal->userSession;
		$this->groupManager = $principal->groupManager;
	}//end __construct()
}//end class
