<?php

/**
 * OpenRegister announcement recipients
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;

/**
 * Who hears about a security setting change and how: the notification manager and the administrator group.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class AnnouncementRecipients {
	/**
	 * Bundle the collaborators.
	 *
	 * @param INotificationManager $notifications Delivers the announcement.
	 * @param IGroupManager        $groupManager  Finds the administrators.
	 */
	public function __construct(
		public readonly INotificationManager $notifications,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
