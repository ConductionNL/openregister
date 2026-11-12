<?php

/**
 * Turns a uid into the name a person is known by.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Notification;

use OCP\IUserManager;

/**
 * A uid is an identifier, not a name.
 *
 * Asking somebody to grant rights to `j.devries3` when the person they know is
 * "Jan de Vries" makes the decision harder for every reader and materially harder
 * for one using a screen reader, who cannot glance at a face beside it.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DisplayNames {

	/**
	 * Constructor.
	 *
	 * @param IUserManager|null $userManager Resolves a uid; absent, the uid is shown,
	 *                                       which is worse copy but never a wrong name.
	 */
	public function __construct(private readonly ?IUserManager $userManager) {
	}//end __construct()

	/**
	 * The display name for a uid, falling back to the uid itself.
	 *
	 * @param string $uid The uid.
	 *
	 * @return string The display name, or the uid when it cannot be resolved.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function forUid(string $uid): string {
		if ($this->userManager === null || $uid === '') {
			return $uid;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return $uid;
		}

		$name = trim($user->getDisplayName());

		if ($name === '') {
			return $uid;
		}

		return $name;
	}//end forUid()
}//end class
