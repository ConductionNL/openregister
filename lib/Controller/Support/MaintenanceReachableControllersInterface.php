<?php

/**
 * The controllers maintenance mode never closes.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Controller\OperationsConsistencyController;
use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Controller\OperationsMaintenanceController;
use OCA\OpenRegister\Controller\OperationsQueryController;

/**
 * The list of controllers that stay reachable while maintenance mode holds.
 *
 * Kept in the Controller component, so the maintenance middleware reads the
 * list without naming a controller class itself.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
interface MaintenanceReachableControllersInterface {

	/**
	 * The controllers that stay reachable while the mode holds, by class.
	 *
	 * Every controller serving a route under /api/operations belongs here;
	 * MaintenanceModeMiddlewareTest reads the routes to hold that.
	 *
	 * @var array<int, string>
	 */
	public const ALWAYS_REACHABLE = [
		OperationsConsoleController::class,
		// The verbs that enter and leave maintenance mode. Closed by the mode
		// they administer, an administrator who entered it over the API could
		// not leave it again without occ.
		OperationsMaintenanceController::class,
		// The consistency check and its repair plan, which an administrator
		// runs while the instance is closed for exactly that repair.
		OperationsConsistencyController::class,
		// The read-only query console, so an administrator can still look at
		// the data while the instance is closed for maintenance.
		OperationsQueryController::class,
	];
}//end interface
