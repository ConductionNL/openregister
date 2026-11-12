<?php

/**
 * QueuedNotificationMapperInterface: the public surface of QueuedNotificationMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Db
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

namespace OCA\OpenRegister\Notifications\Db;

/**
 * Every public method of QueuedNotificationMapper. Callers depend on this interface, not on the class.
 */
interface QueuedNotificationMapperInterface {

	/**
	 * Every currently-queued row, oldest first.
	 *
	 * The flush job re-evaluates each row's holding condition live rather
	 * than filtering "due" rows in SQL — see class docblock.
	 *
	 * @return array<int, QueuedNotification>
	 */
	public function findAll(): array;
}//end interface
