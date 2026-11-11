<?php

/**
 * The services of the notification history API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingService;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The services of the notification history API.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class NotificationHistoryControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param NotificationHistoryMapper   $mapper       Mapper for the notification history table.
	 * @param IUserSession                $userSession  Active session, drives the per-caller filter scope.
	 * @param IGroupManager               $groupManager Group resolver, admins keep full audit visibility.
	 * @param NotificationClearingService $clearing     The one writer of read, snooze and archive.
	 */
	public function __construct(
		public NotificationHistoryMapper $mapper,
		public IUserSession $userSession,
		public IGroupManager $groupManager,
		public NotificationClearingService $clearing,
	) {
	}//end __construct()
}//end class
