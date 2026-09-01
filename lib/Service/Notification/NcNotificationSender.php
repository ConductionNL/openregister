<?php

/**
 * The nc-notification channel sender, as a call-shared unit.
 *
 * Extracted from AnnotationNotificationDispatcher so both callers — the
 * declarative dispatcher and the flow messaging service — persist and push an
 * in-app notification through ONE implementation, with the web-push delivery
 * riding along under the same rules for both. The unit checks the channel's
 * kill switch itself, which is what makes the switch reach both callers by
 * construction rather than by each caller remembering to ask.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use DateTime;
use OCA\OpenRegister\BackgroundJob\WebPushDispatchJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Persists and pushes a single in-app Nextcloud notification.
 */
class NcNotificationSender {

	public const OUTCOME_DISPATCHED = 'dispatched';

	public const OUTCOME_KILL_SWITCH = 'kill-switch';

	public const OUTCOME_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notificationManager Nextcloud notification API.
	 * @param LoggerInterface $logger Logger for dispatch diagnostics.
	 * @param IUserManager|null $userManager User resolver for the web-push ride-along.
	 * @param IJobList|null $jobList Job list used to enqueue the web-push dispatch job.
	 * @param NotificationChannelPolicy|null $channelPolicy The subsystem's per-channel kill switches; null means enabled.
	 */
	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly LoggerInterface $logger,
		private readonly ?IUserManager $userManager = null,
		private readonly ?IJobList $jobList = null,
		private readonly ?NotificationChannelPolicy $channelPolicy = null,
	) {

	}//end __construct()

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
	 * @spec openspec/changes/openregister-notification-body/specs/notificatie-engine/spec.md
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function send(
		string $uid,
		ObjectEntity $object,
		string $subjectKey,
		string $name,
		string $subject,
		string $message,
		array $context,
		string $originApp = 'openregister',
		array $actions = [],
		bool $webPushActive = false,
	): string {
		if ($this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'nc-notification') === false) {
			return self::OUTCOME_KILL_SWITCH;
		}

		$objectUuid = (string)($object->getUuid() ?? '');
		$tagSuffix = $name;
		if ($objectUuid !== '') {
			$tagSuffix = $objectUuid;
		}

		$linkParams = [
			'objectTitle' => (string)($object->getName() ?? $objectUuid),
			'registerId' => $object->getRegister(),
			'schemaId' => $object->getSchema(),
			'objectUuid' => $objectUuid,
			// The resolved origin app drives the notifier icon (originApp hex
			// composite) and the deeplink base for declared actions.
			'originApp' => $originApp,
			// Declared, server-resolved action buttons. The notifier renders
			// these via addAction(); an empty array keeps the implicit "View".
			'_actions' => $actions,
			// Pre-interpolated notification BODY (distinct from the title).
			// The notifier sets it via setParsedMessage() when non-empty;
			// an empty string leaves the body unset (back-compat).
			'_message' => $message,
			// Stable notification tag used by the Service Worker / foreground
			// client to COLLAPSE the web-push and the stock popup so the
			// recipient never sees a duplicate. Keyed by (rule, object).
			'_tag' => sprintf('openregister-%s-%s', $name, $tagSuffix),
			// Foreground-suppression flag: when web-push is active for this
			// rule, an open tab that holds an active push subscription
			// declines to render the plain duplicate popup for this tag
			// (see js/openregister-push-sw.js + src/webpush/register.js).
			'_suppressForegroundPopup' => $webPushActive,
		];

		$objectRef = $name;
		if ($objectUuid !== '') {
			$objectRef = $objectUuid;
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp('openregister')
				->setUser($uid)
				->setDateTime(new DateTime())
				->setObject('object', $objectRef)
				->setSubject(
					$subjectKey,
					array_merge($context, $linkParams, ['_text' => $subject, 'notificationType' => $name])
				);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('Notification "%s" to "%s" failed: %s', $name, $uid, $e->getMessage())
			);
			return self::OUTCOME_FAILED;
		}

		return self::OUTCOME_DISPATCHED;
	}//end send()

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
	 * @spec openspec/changes/openregister-web-push-engine/specs/notificatie-engine/spec.md
	 */
	public function enqueueWebPush(
		array $recipients,
		string $ruleId,
		string $originApp,
		string $subject,
		string $message,
		array $actions,
		ObjectEntity $object,
	): void {
		if ($this->jobList === null) {
			$this->logger->debug('[NcNotificationSender] web-push declared but IJobList unavailable.');
			return;
		}

		if ($this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'nc-notification') === false) {
			return;
		}

		$objectUuid = (string)($object->getUuid() ?? '');
		$tagSuffix = $ruleId;
		if ($objectUuid !== '') {
			$tagSuffix = $objectUuid;
		}

		$tag = sprintf('openregister-%s-%s', $ruleId, $tagSuffix);

		foreach ($recipients as $uid) {
			if (is_string($uid) === false || $uid === '' || $this->recipientExists(uid: $uid) === false) {
				continue;
			}

			$this->jobList->add(
				WebPushDispatchJob::class,
				[
					'uid' => $uid,
					'ruleId' => $ruleId,
					'originApp' => $originApp,
					'title' => $subject,
					'body' => $message,
					'tag' => $tag,
					'actions' => $actions,
				]
			);
		}//end foreach
	}//end enqueueWebPush()

	/**
	 * Whether a uid names a real user; true when no user manager was injected
	 * (the caller has then already verified its recipients).
	 *
	 * @param string $uid Candidate uid.
	 *
	 * @return bool True when the uid may be enqueued.
	 */
	private function recipientExists(string $uid): bool {
		if ($this->userManager === null) {
			return true;
		}

		try {
			return $this->userManager->userExists($uid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NcNotificationSender] userExists check failed for "%s": %s', $uid, $e->getMessage())
			);
			return false;
		}
	}//end recipientExists()
}//end class
