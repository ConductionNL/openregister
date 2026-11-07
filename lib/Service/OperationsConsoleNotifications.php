<?php

/**
 * The notifications the operations console lists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapperInterface;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapperInterface;
use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;

/**
 * The notification dispatch history, the queue and the shipped templates the operations console reads.
 *
 * @spec openspec/specs/operations-console/spec.md
 */
final class OperationsConsoleNotifications {
	/**
	 * Bundle the collaborators.
	 *
	 * @param NotificationHistoryMapperInterface $dispatches The notification dispatch history.
	 * @param QueuedNotificationMapperInterface  $queue      The notifications waiting to go out.
	 * @param NotificationTemplateRegistry       $templates  The shipped notification texts.
	 *
	 * @spec openspec/specs/operations-console/spec.md
	 */
	public function __construct(
		public readonly NotificationHistoryMapperInterface $dispatches,
		public readonly QueuedNotificationMapperInterface $queue,
		public readonly NotificationTemplateRegistry $templates,
	) {
	}//end __construct()
}//end class
