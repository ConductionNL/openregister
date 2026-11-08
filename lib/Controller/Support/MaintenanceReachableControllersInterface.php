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

use OCA\OpenRegister\Controller\OperationsConsoleController;

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
	 * @var array<int, string>
	 */
	public const ALWAYS_REACHABLE = [OperationsConsoleController::class];
}//end interface
