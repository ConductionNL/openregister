<?php

/**
 * ActionAuthInterface: the action-level authorization matrix (ADR-023).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUser;

/**
 * Who may invoke which named action, resolved against an admin-managed matrix.
 *
 * Implemented by the AppHost GenericActionAuthService (per leaf app) and by
 * OpenRegisterActionAuthService (OpenRegister's own matrix). Services type
 * against this contract instead of the AppHost class.
 */
interface ActionAuthInterface {

	/**
	 * Require that the user may perform the named action.
	 *
	 * @param IUser  $user   The authenticated user.
	 * @param string $action Dot-separated action name (e.g. "item.publish").
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user's groups don't match.
	 */
	public function requireAction(IUser $user, string $action): void;

	/**
	 * Check whether the user may perform the named action (non-throwing).
	 *
	 * @param IUser  $user   The authenticated user.
	 * @param string $action Dot-separated action name.
	 *
	 * @return bool True if the user may perform the action.
	 */
	public function can(IUser $user, string $action): bool;

	/**
	 * Groups allowed to perform the action, or ["admin"] when undeclared.
	 *
	 * @param string $action Dot-separated action name.
	 *
	 * @return array<int, string>
	 */
	public function getAllowedGroups(string $action): array;

	/**
	 * The full action-to-groups matrix.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function getMatrix(): array;

	/**
	 * Write the full matrix. The caller enforces admin-only access.
	 *
	 * @param array<string, array<int, string>> $matrix The new matrix.
	 *
	 * @return void
	 *
	 * @throws \JsonException When the matrix cannot be encoded.
	 */
	public function setMatrix(array $matrix): void;

	/**
	 * List all action keys currently in the matrix.
	 *
	 * @return array<int, string>
	 */
	public function getActions(): array;
}//end interface
