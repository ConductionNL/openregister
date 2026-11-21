<?php

/**
 * NotificationClearingServiceInterface: the public surface of NotificationClearingService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Kernel\Contracts\Core\ReadNoticeClearingInterface;

/**
 * Every public method of NotificationClearingService. Callers depend on this interface, not on the class.
 *
 * The two read-driven clearings are declared by the Core port it extends, so the
 * object read-state API can ask for them without naming Notifications.
 */
interface NotificationClearingServiceInterface extends ReadNoticeClearingInterface {

	/**
	 * Archive every notice about an object that has been deleted.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many notices were archived.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-notification-is-cleared-by-opening-what-it-was-about-req-ors-003
	 */
	public function archiveOrphaned(string $objectUuid): int;
}//end interface
