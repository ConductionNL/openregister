<?php

/**
 * NcNotificationSenderInterface: the public surface of NcNotificationSender, for callers to type against.
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


/**
 * Every public method of NcNotificationSender. Callers depend on this interface, not on the class.
 */
interface NcNotificationSenderInterface {

	/**
	 * Persist + dispatch a single in-app Nextcloud notification row.
	 *
	 * The INotification carries the canonical subject key (which the
	 * Notifier switches on to render localised text + an object-detail
	 * action link), the routing parameters the action link needs
	 * (`objectTitle`, `registerId`, `schemaId`, `objectUuid`), the rule's
	 * own name under `notificationType`, and the pre-rendered subject text
	 * under `_text` (so a schema's custom per-locale subject still wins).
	 *
	 * Push delivery needs no extra code: `notify_push` auto-intercepts this
	 * same `IManager::notify()` call and relays it to connected devices.
	 *
	 * @param InAppNotice $notice The recipient, the object, the rule and what it says.
	 *
	 * @return string One of the NcNotificationSender::OUTCOME_* constants.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function notify(InAppNotice $notice): string;

	/**
	 * Enqueue the web-push ride-along for a set of recipients.
	 *
	 * Routed out of band: a background job per recipient so the originating
	 * request is never blocked on push I/O. The job re-resolves recipients to
	 * their stored subscriptions and sends the encrypted VAPID payload. The
	 * ride-along follows the nc-notification channel's kill switch: web-push
	 * is a delivery detail of that channel, not a channel of its own.
	 *
	 * @param PushRideAlong $push The recipients, the object, the rule and the push's subject and body.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function enqueuePush(PushRideAlong $push): void;
}//end interface
