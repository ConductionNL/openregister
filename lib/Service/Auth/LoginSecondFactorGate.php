<?php

/**
 * OpenRegister LoginSecondFactorGate
 *
 * The app's own password login creates a session outside Nextcloud's login
 * flow, so it cannot ask for a second factor. This says whether a user needs
 * one; such a user is refused there and signs in through Nextcloud instead.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Auth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Auth;

use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/**
 * Whether a user has, or must have, a second factor.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
class LoginSecondFactorGate {

	/**
	 * Backup codes alone are not a second factor (as in core's TwoFactorAuth\Manager).
	 */
	private const BACKUP_CODES = 'backup_codes';

	/**
	 * Constructor.
	 *
	 * @param IRegistry     $registry     The user's enabled two-factor providers.
	 * @param IConfig       $config       The instance's two-factor enforcement settings.
	 * @param IGroupManager $groupManager Group membership for enforced and excluded groups.
	 */
	public function __construct(
		private readonly IRegistry $registry,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the user has an enabled second factor or is required to have one.
	 *
	 * Fails closed: when the providers cannot be read, the answer is yes.
	 *
	 * @param IUser $user The user who passed the password check.
	 *
	 * @return bool True when a password alone must not sign this user in.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function requiresSecondFactor(IUser $user): bool {
		try {
			foreach ($this->registry->getProviderStates($user) as $providerId => $enabled) {
				if ($enabled === true && $providerId !== self::BACKUP_CODES) {
					return true;
				}
			}

			return $this->isEnforcedFor(user: $user);
		} catch (Throwable $e) {
			return true;
		}
	}//end requiresSecondFactor()

	/**
	 * Whether the instance enforces two-factor authentication for this user.
	 *
	 * Mirrors core's MandatoryTwoFactor: enforced groups, when listed, win over
	 * excluded groups; otherwise everyone outside an excluded group is enforced.
	 *
	 * @param IUser $user The user.
	 *
	 * @return bool True when two-factor authentication is mandatory for the user.
	 */
	private function isEnforcedFor(IUser $user): bool {
		// Core writes the string 'true', but a hand-edited config.php may hold a
		// boolean; both mean enforced, anything else means not enforced.
		if (in_array($this->config->getSystemValue('twofactor_enforced', 'false'), [true, 'true'], true) === false) {
			return false;
		}

		$uid      = $user->getUID();
		$enforced = (array)$this->config->getSystemValue('twofactor_enforced_groups', []);
		if ($enforced !== []) {
			foreach ($enforced as $group) {
				if ($this->groupManager->isInGroup($uid, (string)$group) === true) {
					return true;
				}
			}

			return false;
		}

		foreach ((array)$this->config->getSystemValue('twofactor_enforced_excluded_groups', []) as $group) {
			if ($this->groupManager->isInGroup($uid, (string)$group) === true) {
				return false;
			}
		}

		return true;
	}//end isEnforcedFor()
}//end class
