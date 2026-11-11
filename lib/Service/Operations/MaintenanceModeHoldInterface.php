<?php

/**
 * MaintenanceModeHoldInterface: whether maintenance mode holds, and what readers are told.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * What the maintenance mode middleware needs of MaintenanceModeService.
 * Bound to MaintenanceModeService in ContractBindings, so it resolves to the same shared instance.
 */
interface MaintenanceModeHoldInterface {

	/**
	 * Is the instance closed.
	 *
	 * @return bool True while the mode holds.
	 */
	public function holds(): bool;

	/**
	 * The message readers are given.
	 *
	 * @return string The administered message, or the shipped one.
	 */
	public function message(): string;
}//end interface
