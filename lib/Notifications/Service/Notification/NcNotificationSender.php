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
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTime;
use OCA\OpenRegister\Notifications\BackgroundJob\WebPushDispatchJob;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Persists and pushes a single in-app Nextcloud notification.
 */
class NcNotificationSender implements NcNotificationSenderInterface {

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
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function notify(InAppNotice $notice): string {
		return $this->dispatch(
			uid: $notice->uid,
			notice: [
				'object' => $notice->object,
				'subjectKey' => $notice->content->subjectKey,
				'name' => $notice->rule->name,
				'subject' => $notice->content->subject,
				'message' => $notice->content->message,
				'context' => $notice->content->context,
				'originApp' => $notice->rule->originApp,
				'actions' => $notice->rule->actions,
				'webPushActive' => $notice->rule->webPushActive,
			]
		);
	}//end notify()

	/**
	 * Persist + dispatch one notification unless the channel is switched off.
	 *
	 * @param string               $uid    Recipient user UID.
	 * @param array<string, mixed> $notice The fields of {@see send()} other than the uid, keyed by parameter name.
	 *
	 * @return string One of the OUTCOME_* constants.
	 */
	private function dispatch(string $uid, array $notice): string {
		if ($this->channelDisabled() === true) {
			return self::OUTCOME_KILL_SWITCH;
		}

		$objectUuid = (string)($notice['object']->getUuid() ?? '');
		$notice['objectUuid'] = $objectUuid;
		$notice['ref'] = $notice['name'];
		if ($objectUuid !== '') {
			$notice['ref'] = $objectUuid;
		}

		$notice['linkParams'] = $this->linkParams(notice: $notice);

		return $this->persist(uid: $uid, notice: $notice);
	}//end dispatch()

	/**
	 * The routing and rendering parameters the notifier reads from the subject.
	 *
	 * @param array<string, mixed> $notice The notice, with its `objectUuid` and its `ref` (the uuid, else the rule name).
	 *
	 * @return array<string, mixed> The link parameters.
	 */
	private function linkParams(array $notice): array {
		$object = $notice['object'];

		return [
			'objectTitle' => (string)($object->getName() ?? $notice['objectUuid']),
			'registerId' => $object->getRegister(),
			'schemaId' => $object->getSchema(),
			'objectUuid' => $notice['objectUuid'],
			// The resolved origin app drives the notifier icon (originApp hex
			// composite) and the deeplink base for declared actions.
			'originApp' => $notice['originApp'],
			// Declared, server-resolved action buttons. The notifier renders
			// these via addAction(); an empty array keeps the implicit "View".
			'_actions' => $notice['actions'],
			// Pre-interpolated notification BODY (distinct from the title).
			// The notifier sets it via setParsedMessage() when non-empty;
			// an empty string leaves the body unset (back-compat).
			'_message' => $notice['message'],
			// Stable notification tag used by the Service Worker / foreground
			// client to COLLAPSE the web-push and the stock popup so the
			// recipient never sees a duplicate. Keyed by (rule, object).
			'_tag' => sprintf('openregister-%s-%s', $notice['name'], $notice['ref']),
			// Foreground-suppression flag: when web-push is active for this
			// rule, an open tab that holds an active push subscription
			// declines to render the plain duplicate popup for this tag
			// (see js/openregister-push-sw.js + src/webpush/register.js).
			'_suppressForegroundPopup' => $notice['webPushActive'],
		];
	}//end linkParams()

	/**
	 * Create and notify the notification row, reporting a failure instead of throwing it.
	 *
	 * @param string               $uid    Recipient user UID.
	 * @param array<string, mixed> $notice The notice, with its `ref` and `linkParams`.
	 *
	 * @return string OUTCOME_DISPATCHED or OUTCOME_FAILED.
	 */
	private function persist(string $uid, array $notice): string {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp('openregister')
				->setUser($uid)
				->setDateTime(new DateTime())
				->setObject('object', $notice['ref'])
				->setSubject(
					$notice['subjectKey'],
					array_merge(
						$notice['context'],
						$notice['linkParams'],
						['_text' => $notice['subject'], 'notificationType' => $notice['name']]
					)
				);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('Notification "%s" to "%s" failed: %s', $notice['name'], $uid, $e->getMessage())
			);
			return self::OUTCOME_FAILED;
		}

		return self::OUTCOME_DISPATCHED;
	}//end persist()

	/**
	 * Whether the nc-notification channel's kill switch is set.
	 *
	 * @return bool True when the channel is switched off.
	 */
	private function channelDisabled(): bool {
		return $this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'nc-notification') === false;
	}//end channelDisabled()

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
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function enqueuePush(PushRideAlong $push): void {
		$this->enqueueFor(
			recipients: $push->recipients,
			push: [
				'ruleId' => $push->rule->name,
				'originApp' => $push->rule->originApp,
				'title' => $push->content->subject,
				'body' => $push->content->message,
				'actions' => $push->rule->actions,
				'object' => $push->object,
			]
		);
	}//end enqueuePush()

	/**
	 * Enqueue one web-push job per enqueueable recipient, unless push cannot or may not ride along.
	 *
	 * @param array<int, mixed>    $recipients Recipient uids.
	 * @param array<string, mixed> $push       The rule id, origin app, title, body, actions and object.
	 *
	 * @return void
	 */
	private function enqueueFor(array $recipients, array $push): void {
		if ($this->jobList === null) {
			$this->logger->debug('[NcNotificationSender] web-push declared but IJobList unavailable.');
			return;
		}

		if ($this->channelDisabled() === true) {
			return;
		}

		$objectUuid = (string)($push['object']->getUuid() ?? '');
		$tagSuffix = $push['ruleId'];
		if ($objectUuid !== '') {
			$tagSuffix = $objectUuid;
		}

		$payload = [
			'ruleId' => $push['ruleId'],
			'originApp' => $push['originApp'],
			'title' => $push['title'],
			'body' => $push['body'],
			'tag' => sprintf('openregister-%s-%s', $push['ruleId'], $tagSuffix),
			'actions' => $push['actions'],
		];

		foreach ($recipients as $uid) {
			if ($this->enqueueable(uid: $uid) === true) {
				$this->jobList->add(WebPushDispatchJob::class, ['uid' => $uid] + $payload);
			}
		}
	}//end enqueueFor()

	/**
	 * Whether a recipient is a non-empty string naming a real user.
	 *
	 * @param mixed $uid Candidate uid.
	 *
	 * @return bool True when the uid may be enqueued.
	 */
	private function enqueueable(mixed $uid): bool {
		return is_string($uid) === true && $uid !== '' && $this->recipientExists(uid: $uid) === true;
	}//end enqueueable()

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
