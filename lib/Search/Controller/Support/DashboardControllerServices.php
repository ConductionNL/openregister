<?php

/**
 * OpenRegister dashboard controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller\Support
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

namespace OCA\OpenRegister\Search\Controller\Support;

use OCA\OpenRegister\Search\Service\DashboardService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The services of the dashboard API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-5
 */
final class DashboardControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DashboardService $dashboardService The dashboard service
	 * @param LoggerInterface  $logger           Logger for error tracking
	 * @param IUserSession     $userSession      Active user session for caller identity
	 * @param IGroupManager    $groupManager     Group manager for admin checks
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-5
	 */
	public function __construct(
		public readonly DashboardService $dashboardService,
		public readonly LoggerInterface $logger,
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
