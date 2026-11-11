<?php

/**
 * The web-push delivery riding along with an in-app notice.
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
 * The web-push delivery that rides along with in-app notices: the recipients,
 * the object, the rule and what the push says (its subject and body).
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final class PushRideAlong {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $recipients Recipient uids.
	 * @param ObjectEntity       $object The object the event happened on.
	 * @param NoticeRule         $rule The rule it is sent under (name, origin app, actions).
	 * @param NoticeContent      $content What the push says (subject and message).
	 */
	public function __construct(
		public readonly array $recipients,
		public readonly ObjectEntity $object,
		public readonly NoticeRule $rule,
		public readonly NoticeContent $content,
	) {
	}//end __construct()
}//end class
