<?php

/**
 * MaintenanceReachableInterface: a controller maintenance mode never closes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Configuration;

/**
 * Marks a controller that stays reachable while maintenance mode holds.
 *
 * The operations console, the maintenance verbs and the consistency check
 * implement it: an administrator who could not reach them could not leave the
 * mode again. The maintenance middleware asks for the marker, so it names no
 * controller class itself.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
interface MaintenanceReachableInterface {
}//end interface
