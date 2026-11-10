<?php

/**
 * PasswordChange: a password change the user asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

/**
 * The current password to verify and the new password to set.
 *
 * ADR-011 Rule 2 operation object for UserService::applyPasswordChange().
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final class PasswordChange {

	/**
	 * Describe it.
	 *
	 * @param string $currentPassword The current password, for verification.
	 * @param string $newPassword     The new password to set.
	 */
	public function __construct(
		public readonly string $currentPassword,
		public readonly string $newPassword,
	) {
	}//end __construct()
}//end class
