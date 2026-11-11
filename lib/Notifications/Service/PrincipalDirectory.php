<?php

/**
 * The server's principals as the notification component reads them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Who exists, which groups they are in, and who is calling.
 *
 * A dependency aggregate (ADR-011 Rule 4), autowired by the container and
 * consumed by TaskPoolRecipientResolver, TextBlockService and
 * NotificationClearingService. It holds no permission or tenancy guard.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
 */
final class PrincipalDirectory {
	/**
	 * Bundle the principal services.
	 *
	 * @param IUserManager  $userManager  Confirms a user exists.
	 * @param IGroupManager $groupManager Resolves groups and their members.
	 * @param IUserSession  $userSession  The calling user.
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly IGroupManager $groupManager,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
