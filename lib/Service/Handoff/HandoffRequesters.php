<?php

/**
 * OpenRegister Handoff Requesters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
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

namespace OCA\OpenRegister\Service\Handoff;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

/**
 * Who asks for a handoff and who hears about it: the acting user, the requester lookup, notifications and events.
 *
 * @spec openspec/specs/semantic-object-handoff/spec.md#requirement-handoffservice-executes-conversions-on-top-of-semantictyperesolver
 */
final readonly class HandoffRequesters {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession $userSession The acting user (and drain impersonation).
	 * @param IUserManager $userManager Requesting-user lookup for queue drain.
	 * @param INotificationManager $notificationManager Requester notification on drain permission failure.
	 * @param IEventDispatcher $eventDispatcher Post-commit ADR-041 event dispatch.
	 */
	public function __construct(
		public IUserSession $userSession,
		public IUserManager $userManager,
		public INotificationManager $notificationManager,
		public IEventDispatcher $eventDispatcher,
	) {
	}//end __construct()
}//end class
