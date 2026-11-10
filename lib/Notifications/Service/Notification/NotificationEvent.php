<?php

/**
 * NotificationEvent: what happened to an object, as the notification rules match it.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object an event happened on, the trigger, and the trigger's extras.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
 */
final class NotificationEvent {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $object  The object (or virtual adapter) the event happened on.
	 * @param string               $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $trigger,
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
