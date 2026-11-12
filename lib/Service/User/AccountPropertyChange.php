<?php

/**
 * AccountPropertyChange: one profile field written to the user's account.
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
 * @spec openspec/specs/account-self-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

/**
 * One AccountManager property and the value a profile update writes to it.
 *
 * ADR-011 Rule 2 component-local value object for UserService::writeAccountProperty().
 *
 * @internal
 *
 * @spec openspec/specs/account-self-service/spec.md
 */
final class AccountPropertyChange {

	/**
	 * Describe it.
	 *
	 * @param string $property The AccountManager property name.
	 * @param string $value    The new value.
	 */
	public function __construct(
		public readonly string $property,
		public readonly string $value,
	) {
	}//end __construct()
}//end class
