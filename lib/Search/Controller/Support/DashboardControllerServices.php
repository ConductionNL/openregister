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

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Search\Service\DashboardService;

/**
 * The services of the dashboard API.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-5
 */
final class DashboardControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param DashboardService  $dashboardService The dashboard service
	 * @param ControllerSession $session          Session, group manager (admin checks) and logger
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-graphql-rt-dash/tasks.md#task-5
	 */
	public function __construct(
		public readonly DashboardService $dashboardService,
		public readonly ControllerSession $session,
	) {
	}//end __construct()
}//end class
