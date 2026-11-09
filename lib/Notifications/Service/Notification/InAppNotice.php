<?php

/**
 * One in-app notification to one recipient.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One in-app Nextcloud notification for one recipient about one object.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final class InAppNotice {
	/**
	 * Constructor.
	 *
	 * @param string        $uid Recipient user UID.
	 * @param ObjectEntity  $object The object the event happened on.
	 * @param NoticeRule    $rule The rule it is sent under.
	 * @param NoticeContent $content What it says.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly ObjectEntity $object,
		public readonly NoticeRule $rule,
		public readonly NoticeContent $content,
	) {
	}//end __construct()
}//end class
