<?php

/**
 * OpenRegister ViewAlertCrossedListener
 *
 * Tells the people a view alert names that the view's count crossed its line.
 *
 * ViewAlertSweepJob decides WHEN an alert fires and dispatches one
 * ViewAlertCrossedEvent per crossing. This listener decides WHO hears it and
 * HOW. It is not one of the object-shaped senders: those build a deeplink from
 * an object's register, schema and uuid, and a view alert is about a number,
 * not an object. Inventing an object to satisfy their signature would put a
 * fabricated record in the link a person is told to click.
 *
 * Recipients use the RBAC string grammar the rest of the app already speaks
 * (DenyResolver reads the same `user:` prefix): `user:<uid>` names a person, a bare string
 * names a Nextcloud group. Both go through NotificationRecipientResolver, so a
 * view alert verifies uids and expands groups exactly as a schema rule does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers a view alert's crossing to its recipients on its declared channels.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 *
 * @template-implements IEventListener<Event>
 */
class ViewAlertCrossedListener implements IEventListener {

	/**
	 * The notification subject Notifier renders.
	 *
	 * @var string
	 */
	public const SUBJECT = 'view_alert_crossed';

	/**
	 * The Nextcloud notification channel, the default when an alert names none.
	 *
	 * @var string
	 */
	public const CHANNEL_NOTIFICATION = 'nc-notification';

	/**
	 * The email channel.
	 *
	 * @var string
	 */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * The prefix that names a person rather than a group, as in the app's RBAC rules.
	 *
	 * @var string
	 */
	public const USER_PREFIX = ViewAlert::USER_PREFIX;

	/**
	 * Constructor.
	 *
	 * @param ViewAlertAudience    $audience      The recipient resolver and the read re-check.
	 * @param INotificationManager $notifications Nextcloud's notification manager.
	 * @param ViewAlertMail        $mail          The email sender, the language factory and the user manager.
	 * @param LoggerInterface      $logger        For recipients and channels nothing could deliver to.
	 */
	public function __construct(
		private readonly ViewAlertAudience $audience,
		private readonly INotificationManager $notifications,
		private readonly ViewAlertMail $mail,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Deliver one crossing.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
	 */
	public function handle(Event $event): void {
		if ($event instanceof ViewAlertCrossedEvent === false) {
			return;
		}

		$alert = $event->getAlert();
		$run = new ViewAlertDeliveryRun();
		$run->parameters = self::parametersOf(event: $event);
		$resolved = $this->resolve(viewId: $run->parameters['viewId'], alert: $alert);
		$run->uids = $this->onlyThoseWhoMayRead(view: $event->getView()->jsonSerialize(), uids: $resolved);
		foreach ($alert->channels as $channel) {
			$this->deliver(run: $run, channel: $channel);
		}
	}//end handle()

	/**
	 * The subject parameters of the crossing: view, count and the alert's bound.
	 *
	 * @param ViewAlertCrossedEvent $event The crossing.
	 *
	 * @return array{view: string, viewId: string, count: int, operator: string, threshold: int|float} The parameters.
	 */
	private static function parametersOf(ViewAlertCrossedEvent $event): array {
		$view = $event->getView();
		$alert = $event->getAlert();
		return [
			'view' => (string)$view->getName(),
			'viewId' => (string)$view->getUuid(),
			'count' => $event->getCount(),
			'operator' => $alert->operator,
			'threshold' => $alert->threshold,
		];
	}//end parametersOf()

	/**
	 * Deliver the crossing over one channel, or name a channel nothing delivers.
	 *
	 * @param ViewAlertDeliveryRun $run     The delivery: recipients and subject parameters.
	 * @param string               $channel The channel the alert names.
	 *
	 * @return void
	 */
	private function deliver(ViewAlertDeliveryRun $run, string $channel): void {
		if ($channel === self::CHANNEL_NOTIFICATION) {
			$this->notifyAll(run: $run);
			return;
		}

		if ($channel === self::CHANNEL_EMAIL) {
			$this->mailAll(run: $run);
			return;
		}

		// A channel this listener does not deliver is said out loud. The
		// declaration reads, so the save accepted it, and a recipient
		// waiting on a pager that never rings deserves a line in the log.
		$this->logger->warning(
			'[ViewAlertCrossedListener] View {view} alert names channel {channel}, which nothing delivers; it was skipped.',
			['view' => $run->parameters['viewId'], 'channel' => $channel]
		);
	}//end deliver()

	/**
	 * Drop, and log, every recipient who may no longer read the view.
	 *
	 * 🔴 RECIPIENTS ARE JUDGED WHEN THE ALERT IS SAVED, AND REACH CHANGES AFTER.
	 * An owner removes a share or un-publishes the view, and the recipient who
	 * could read it at save time keeps receiving its name and count by
	 * notification and email. So the question is asked again at the moment of
	 * delivery, with the same rule the save used (the owner, an administrator,
	 * or whoever the view's shares and `isPublic` reach). An unreadable
	 * membership drops the recipient: not telling someone is the safe error.
	 *
	 * @param array<string, mixed> $view The serialised view that crossed.
	 * @param array<int, string>   $uids The verified recipients.
	 *
	 * @return array<int, string> The recipients who still read it.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	private function onlyThoseWhoMayRead(array $view, array $uids): array {
		[$readers, $dropped] = $this->splitByReach(view: $view, uids: $uids);
		if ($dropped !== []) {
			$this->logger->warning(
				'[ViewAlertCrossedListener] View {view} alert skipped recipients who may no longer read the view: {recipients}',
				['view' => (string)($view['uuid'] ?? ''), 'recipients' => implode(', ', $dropped)]
			);
		}

		return $readers;
	}//end onlyThoseWhoMayRead()

	/**
	 * The recipients who read the view, and those who no longer do.
	 *
	 * @param array<string, mixed> $view The serialised view.
	 * @param array<int, string>   $uids The recipients.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The readers and the dropped.
	 */
	private function splitByReach(array $view, array $uids): array {
		$readers = [];
		$dropped = [];
		foreach ($uids as $uid) {
			if ($this->audience->viewers->recipientReads(view: $view, recipient: self::USER_PREFIX . $uid) === true) {
				$readers[] = $uid;
				continue;
			}

			$dropped[] = $uid;
		}

		return [$readers, $dropped];
	}//end splitByReach()

	/**
	 * The verified uids an alert names, with every name that resolved to nobody logged.
	 *
	 * @param string $viewId The view's uuid, for the log line.
	 * @param ViewAlert $alert The declaration.
	 *
	 * @return array<int, string> Verified, deduplicated uids.
	 */
	private function resolve(string $viewId, ViewAlert $alert): array {
		[$users, $groups] = self::splitRecipients(alert: $alert);
		$resolved = $this->audience->recipients->resolveWithDiagnostics(
			recipientsSpec: [
				['kind' => 'users', 'users' => $users],
				['kind' => 'groups', 'groups' => $groups],
			],
			data: []
		);

		$this->nameUnresolved(viewId: $viewId, unresolved: $this->unresolvedOf(resolved: $resolved, users: $users));

		return array_values(array_unique($resolved['uids']));
	}//end resolve()

	/**
	 * The alert's recipients split into user ids and group ids.
	 *
	 * @param ViewAlert $alert The declaration.
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} The uids and the group ids.
	 */
	private static function splitRecipients(ViewAlert $alert): array {
		$users = [];
		$groups = [];
		foreach ($alert->recipients as $recipient) {
			if (str_starts_with($recipient, self::USER_PREFIX) === true) {
				$users[] = substr($recipient, strlen(self::USER_PREFIX));
				continue;
			}

			$groups[] = $recipient;
		}

		return [$users, $groups];
	}//end splitRecipients()

	/**
	 * The recipients that did not resolve, including named users this server does not have.
	 *
	 * The `users` kind drops an unknown uid without a trace, which is the
	 * right posture for uids read from object data. Here the owner typed
	 * the name, so a missing person is a fault worth naming.
	 *
	 * @param array{unresolved: array<int, array<string, mixed>>} $resolved The resolver's answer.
	 * @param array<int, string>                                   $users    The uids the alert names.
	 *
	 * @return array<int, string> The unresolved recipients.
	 */
	private function unresolvedOf(array $resolved, array $users): array {
		$unresolved = array_column($resolved['unresolved'], 'id');
		foreach ($users as $uid) {
			if ($this->audience->recipients->userExists(uid: $uid) === false) {
				$unresolved[] = self::USER_PREFIX . $uid;
			}
		}

		return $unresolved;
	}//end unresolvedOf()

	/**
	 * Name the recipients this server does not have in the log.
	 *
	 * @param string             $viewId     The view's uuid.
	 * @param array<int, string> $unresolved The unresolved recipients.
	 *
	 * @return void
	 */
	private function nameUnresolved(string $viewId, array $unresolved): void {
		if ($unresolved !== []) {
			$this->logger->warning(
				'[ViewAlertCrossedListener] View {view} alert names recipients this server does not have: {recipients}',
				['view' => $viewId, 'recipients' => implode(', ', $unresolved)]
			);
		}
	}//end nameUnresolved()

	/**
	 * Put the crossing in each recipient's Nextcloud notifications.
	 *
	 * @param ViewAlertDeliveryRun $run The delivery: recipients and the subject parameters Notifier renders.
	 *
	 * @return void
	 */
	private function notifyAll(ViewAlertDeliveryRun $run): void {
		$parameters = $run->parameters;
		foreach ($run->uids as $uid) {
			try {
				$this->notifyOne(uid: $uid, parameters: $parameters);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[ViewAlertCrossedListener] View {view} alert could not notify {uid}: {error}',
					['view' => $parameters['viewId'], 'uid' => $uid, 'error' => $e->getMessage()]
				);
			}
		}
	}//end notifyAll()

	/**
	 * Raise the notification for one recipient.
	 *
	 * @param string               $uid        The recipient.
	 * @param array<string, mixed> $parameters The subject parameters Notifier renders.
	 *
	 * @return void
	 */
	private function notifyOne(string $uid, array $parameters): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp('openregister')
			->setUser($uid)
			->setDateTime(new DateTime())
			->setObject('view', (string)$parameters['viewId'])
			->setSubject(self::SUBJECT, $parameters);
		$this->notifications->notify($notification);
	}//end notifyOne()

	/**
	 * Mail the crossing to each recipient, in their own language.
	 *
	 * @param ViewAlertDeliveryRun $run The delivery: recipients and the view, count and threshold.
	 *
	 * @return void
	 */
	private function mailAll(ViewAlertDeliveryRun $run): void {
		$parameters = $run->parameters;
		foreach ($run->uids as $uid) {
			$l = $this->mail->l10nFactory->get('openregister', $this->mail->l10nFactory->getUserLanguage($this->mail->userManager->get($uid)));
			$subject = $l->t('%1$s is at %2$s', [$parameters['view'], $parameters['count']]);
			$outcome = $this->mail->email->send(uid: $uid, subject: $subject, body: self::mailBody(l: $l, parameters: $parameters));
			if ($outcome !== EmailSenderInterface::OUTCOME_DISPATCHED) {
				$this->logger->warning(
					'[ViewAlertCrossedListener] View {view} alert email to {uid} was not sent: {outcome}',
					['view' => $parameters['viewId'], 'uid' => $uid, 'outcome' => $outcome]
				);
			}
		}
	}//end mailAll()

	/**
	 * The mail body: the count against the threshold, above or below as the alert reads.
	 *
	 * @param IL10N                $l          The recipient's translations.
	 * @param array<string, mixed> $parameters The view, count, operator and threshold.
	 *
	 * @return string The body.
	 */
	private static function mailBody(IL10N $l, array $parameters): string {
		$values = [$parameters['view'], $parameters['count'], $parameters['threshold']];
		if ($parameters['operator'] === ViewAlert::GTE) {
			return $l->t('The view %1$s counts %2$s, at or above its threshold of %3$s.', $values);
		}

		return $l->t('The view %1$s counts %2$s, at or below its threshold of %3$s.', $values);
	}//end mailBody()
}//end class
