<?php

/**
 * OpenRegister AuditQueryController services
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

use OCA\OpenRegister\Service\AuditQueryServiceInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of AuditQueryController, wired by DI autowiring.
 *
 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.1
 */
final class AuditQueryControllerServices {
	/**
	 * Active user session for the admin gate.
	 *
	 * @var IUserSession
	 */
	public readonly IUserSession $userSession;

	/**
	 * Group manager for the admin gate.
	 *
	 * @var IGroupManager
	 */
	public readonly IGroupManager $groupManager;

	/**
	 * Bundle the services.
	 *
	 * @param SessionPrincipal $principal The session and group manager, kept as userSession and groupManager.
	 * @param AuditQueryServiceInterface $auditQueryService Cross-schema audit-entry query service.
	 *
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-2.1
	 */
	public function __construct(
		SessionPrincipal $principal,
		public readonly AuditQueryServiceInterface $auditQueryService,
	) {
		$this->userSession  = $principal->userSession;
		$this->groupManager = $principal->groupManager;
	}//end __construct()
}//end class
