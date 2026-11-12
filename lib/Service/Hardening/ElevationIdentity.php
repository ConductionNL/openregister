<?php

/**
 * OpenRegister elevation identity
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Who asks to elevate and where the elevation is kept: the session, the user session and the user manager.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-administration-requires-a-fresh-expiring-authentication-req-ihc-002
 */
final class ElevationIdentity {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ISession     $session     Holds the moment, and dies with the session.
	 * @param IUserSession $userSession Names the account that is elevating.
	 * @param IUserManager $users       Confirms the password.
	 */
	public function __construct(
		public readonly ISession $session,
		public readonly IUserSession $userSession,
		public readonly IUserManager $users,
	) {
	}//end __construct()
}//end class
