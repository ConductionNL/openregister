<?php

/**
 * MaintenanceModeServiceInterface: what callers outside the Service component ask of MaintenanceModeService.
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
 * Entering, leaving and reading maintenance mode, as the operations
 * maintenance controller uses it. Only the methods that caller calls.
 */
interface MaintenanceModeServiceInterface {

	/**
	 * Close the register for maintenance.
	 *
	 * @param string      $actor   The uid closing it.
	 * @param string|null $message What readers are told.
	 *
	 * @return array<string, mixed> The mode now in force.
	 */
	public function enter(string $actor, ?string $message = null): array;

	/**
	 * Open the register again.
	 *
	 * @param string $actor The uid opening it.
	 *
	 * @return array<string, mixed> The mode now in force.
	 */
	public function leave(string $actor): array;

	/**
	 * Whether maintenance mode holds, the message, who and when.
	 *
	 * @return array<string, mixed> Whether it holds, the message, who and when.
	 */
	public function state(): array;
}//end interface
