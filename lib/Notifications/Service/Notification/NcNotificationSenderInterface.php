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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Every public method of NcNotificationSender. Callers depend on this interface, not on the class.
 */
interface NcNotificationSenderInterface {

	/**
	 * Persist + dispatch a single in-app Nextcloud notification row.
	 *
	 * The INotification carries the canonical `$subjectKey` (which the
	 * Notifier switches on to render localised text + an object-detail
	 * action link), the routing parameters the action link needs
	 * (`objectTitle`, `registerId`, `schemaId`, `objectUuid`), the rule's
	 * own name under `notificationType`, and the pre-rendered subject text
	 * under `_text` (so a schema's custom per-locale subject still wins).
	 *
	 * Push delivery needs no extra code: `notify_push` auto-intercepts this
	 * same `IManager::notify()` call and relays it to connected devices.
	 *
	 * @param string $uid Recipient user UID.
	 * @param ObjectEntity $object The object the event happened on.
	 * @param string $subjectKey Canonical subject identifier (object_created/_updated/_transitioned).
	 * @param string $name Rule name or step identity (notification type identifier).
	 * @param string $subject Pre-interpolated subject text (notification title).
	 * @param string $message Pre-interpolated body text (notification body); may be empty.
	 * @param array<string, mixed> $context Trigger context (action, from, to).
	 * @param string $originApp Resolved originApp (declared or register-owning app).
	 * @param array<int, array<string, mixed>> $actions Resolved action buttons (label map + deeplink url + primary).
	 * @param bool $webPushActive Whether the send also delivers over web-push (drives duplicate suppression).
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The dispatcher's emit signature, moved verbatim:
	 * every argument is one field of the INotification the channel persists.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$webPushActive` is the foreground-suppression
	 * flag on the notification tag, a delivery detail of this channel, not a second responsibility.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function send(
		string $uid, ObjectEntity $object, string $subjectKey, string $name, string $subject, string $message, array $context,
		string $originApp = 'openregister', array $actions = [], bool $webPushActive = false
	): string;

	/**
	 * Persist + dispatch a single in-app notification row; the value-object form of {@see self::send()}.
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
	 * ride-along follows the nc-notification channel's kill switch — web-push
	 * is a delivery detail of that channel, not a channel of its own.
	 *
	 * @param array<int, string> $recipients Recipient uids.
	 * @param string $ruleId Rule name or step identity.
	 * @param string $originApp Resolved origin app.
	 * @param string $subject Pre-interpolated title.
	 * @param string $message Pre-interpolated body.
	 * @param array<int, array<string, mixed>> $actions Resolved action buttons.
	 * @param ObjectEntity $object The object the event happened on.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function enqueueWebPush(
		array $recipients, string $ruleId, string $originApp, string $subject, string $message, array $actions, ObjectEntity $object
	): void;

	/**
	 * Enqueue the web-push ride-along; the value-object form of {@see self::enqueueWebPush()}.
	 *
	 * @param PushRideAlong $push The recipients, the object, the rule and the push's subject and body.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function enqueuePush(PushRideAlong $push): void;
}//end interface
