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
 * (DenyResolver::USER_PREFIX): `user:<uid>` names a person, a bare string
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
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Event\ViewAlertCrossedEvent;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\View\ViewAlert;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use OCP\L10N\IFactory;
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
	 * Constructor.
	 *
	 * @param NotificationRecipientResolver $recipients The engine's recipient resolver.
	 * @param INotificationManager $notifications Nextcloud's notification manager.
	 * @param EmailSender $email The engine's email sender.
	 * @param IFactory $l10nFactory For each recipient's own language in an email.
	 * @param IUserManager $userManager To read a recipient's language.
	 * @param LoggerInterface $logger For recipients and channels nothing could deliver to.
	 */
	public function __construct(
		private readonly NotificationRecipientResolver $recipients,
		private readonly INotificationManager $notifications,
		private readonly EmailSender $email,
		private readonly IFactory $l10nFactory,
		private readonly IUserManager $userManager,
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

		$view = $event->getView();
		$alert = $event->getAlert();
		$parameters = [
			'view' => (string)$view->getName(),
			'viewId' => (string)$view->getUuid(),
			'count' => $event->getCount(),
			'operator' => $alert->operator,
			'threshold' => $alert->threshold,
		];

		$uids = $this->resolve(view: $view, alert: $alert);
		foreach ($alert->channels as $channel) {
			if ($channel === self::CHANNEL_NOTIFICATION) {
				$this->notifyAll(uids: $uids, parameters: $parameters);
				continue;
			}

			if ($channel === self::CHANNEL_EMAIL) {
				$this->mailAll(uids: $uids, parameters: $parameters);
				continue;
			}

			// A channel this listener does not deliver is said out loud. The
			// declaration reads, so the save accepted it, and a recipient
			// waiting on a pager that never rings deserves a line in the log.
			$this->logger->warning(
				'[ViewAlertCrossedListener] View {view} alert names channel {channel}, which nothing delivers; it was skipped.',
				['view' => $parameters['viewId'], 'channel' => $channel]
			);
		}
	}//end handle()

	/**
	 * The verified uids an alert names, with every name that resolved to nobody logged.
	 *
	 * @param View $view The view, for the log line.
	 * @param ViewAlert $alert The declaration.
	 *
	 * @return array<int, string> Verified, deduplicated uids.
	 */
	private function resolve(View $view, ViewAlert $alert): array {
		$users = [];
		$groups = [];
		foreach ($alert->recipients as $recipient) {
			if (str_starts_with($recipient, DenyResolver::USER_PREFIX) === true) {
				$users[] = substr($recipient, strlen(DenyResolver::USER_PREFIX));
				continue;
			}

			$groups[] = $recipient;
		}

		$resolved = $this->recipients->resolveWithDiagnostics(
			recipientsSpec: [
				['kind' => 'users', 'users' => $users],
				['kind' => 'groups', 'groups' => $groups],
			],
			data: []
		);

		// The `users` kind drops an unknown uid without a trace, which is the
		// right posture for uids read from object data. Here the owner typed
		// the name, so a missing person is a fault worth naming.
		$unresolved = array_column($resolved['unresolved'], 'id');
		foreach ($users as $uid) {
			if ($this->recipients->userExists(uid: $uid) === false) {
				$unresolved[] = DenyResolver::USER_PREFIX . $uid;
			}
		}

		if ($unresolved !== []) {
			$this->logger->warning(
				'[ViewAlertCrossedListener] View {view} alert names recipients this server does not have: {recipients}',
				['view' => (string)$view->getUuid(), 'recipients' => implode(', ', $unresolved)]
			);
		}

		return array_values(array_unique($resolved['uids']));
	}//end resolve()

	/**
	 * Put the crossing in each recipient's Nextcloud notifications.
	 *
	 * @param array<int, string> $uids The recipients.
	 * @param array<string, mixed> $parameters The subject parameters Notifier renders.
	 *
	 * @return void
	 */
	private function notifyAll(array $uids, array $parameters): void {
		foreach ($uids as $uid) {
			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp('openregister')
					->setUser($uid)
					->setDateTime(new DateTime())
					->setObject('view', (string)$parameters['viewId'])
					->setSubject(self::SUBJECT, $parameters);
				$this->notifications->notify($notification);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[ViewAlertCrossedListener] View {view} alert could not notify {uid}: {error}',
					['view' => $parameters['viewId'], 'uid' => $uid, 'error' => $e->getMessage()]
				);
			}
		}
	}//end notifyAll()

	/**
	 * Mail the crossing to each recipient, in their own language.
	 *
	 * @param array<int, string> $uids The recipients.
	 * @param array<string, mixed> $parameters The view, count and threshold.
	 *
	 * @return void
	 */
	private function mailAll(array $uids, array $parameters): void {
		foreach ($uids as $uid) {
			$l = $this->l10nFactory->get('openregister', $this->l10nFactory->getUserLanguage($this->userManager->get($uid)));
			$subject = $l->t('%1$s is at %2$s', [$parameters['view'], $parameters['count']]);
			$body = $l->t(
				'The view %1$s counts %2$s, at or below its threshold of %3$s.',
				[$parameters['view'], $parameters['count'], $parameters['threshold']]
			);
			if ($parameters['operator'] === ViewAlert::GTE) {
				$body = $l->t(
					'The view %1$s counts %2$s, at or above its threshold of %3$s.',
					[$parameters['view'], $parameters['count'], $parameters['threshold']]
				);
			}

			$outcome = $this->email->send(uid: $uid, subject: $subject, body: $body);
			if ($outcome !== EmailSender::OUTCOME_DISPATCHED) {
				$this->logger->warning(
					'[ViewAlertCrossedListener] View {view} alert email to {uid} was not sent: {outcome}',
					['view' => $parameters['viewId'], 'uid' => $uid, 'outcome' => $outcome]
				);
			}
		}
	}//end mailAll()
}//end class
