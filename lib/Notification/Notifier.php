<?php

/**
 * OpenRegister Notification Provider
 *
 * This file contains the notifier class for displaying notifications
 * in the Nextcloud notification center.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Notification;

use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Class Notifier
 *
 * Handles the preparation of notifications for display in Nextcloud.
 *
 * @package OCA\OpenRegister\Notification
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
class Notifier implements INotifier {
	/**
	 * Constructor
	 *
	 * @param IFactory $factory The L10N factory instance
	 * @param IURLGenerator $urlGenerator URL generator for notification icons and actions
	 * @param IUserManager|null $userManager Resolves a uid to the DISPLAY NAME a
	 *                                       person is asked about. Nullable and
	 *                                       last so the existing hand-built
	 *                                       construction in NotifierTest keeps
	 *                                       binding; absent, the uid is shown,
	 *                                       which is worse copy but never a wrong
	 *                                       name.
	 */
	public function __construct(
		private readonly IFactory $factory,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?IUserManager $userManager = null,
	) {
	}//end __construct()

	/**
	 * The display name for a uid, falling back to the uid itself.
	 *
	 * A uid is an identifier, not a name. Asking somebody to grant rights to
	 * `j.devries3` when the person they know is "Jan de Vries" makes the decision
	 * harder for every reader and materially harder for one using a screen
	 * reader, who cannot glance at a face beside it.
	 *
	 * @param string $uid The uid.
	 *
	 * @return string The display name, or the uid when it cannot be resolved.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function displayName(string $uid): string {
		if ($this->userManager === null || $uid === '') {
			return $uid;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return $uid;
		}

		$name = trim($user->getDisplayName());

		if ($name === '') {
			return $uid;
		}

		return $name;
	}//end displayName()

	/**
	 * Identifier of the notifier.
	 *
	 * Only use [a-z0-9_].
	 *
	 * @return string The notifier ID
	 *
	 * @psalm-return 'openregister'
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function getID(): string {
		return 'openregister';
	}//end getID()

	/**
	 * Human readable name describing the notifier.
	 *
	 * @return string The notifier name
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function getName(): string {
		return $this->factory->get('openregister')->t('OpenRegister');
	}//end getName()

	/**
	 * Prepare notification for display.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param string $languageCode The language code
	 *
	 * @return INotification The prepared notification
	 * @throws UnknownNotificationException If the notification is not from this app
	 *
	 * Declining a notification that is not ours is routine — every notifier is
	 * offered every notification. Nextcloud deprecated InvalidArgumentException
	 * here and logs a warning per throw, so the routine case was filling the log.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'openregister') {
			// Not our notification.
			throw new UnknownNotificationException('Unknown app');
		}

		$l = $this->factory->get('openregister', $languageCode);

		switch ($notification->getSubject()) {
			case 'configuration_update_available':
				return $this->prepareConfigurationUpdate(notification: $notification, l: $l);
			case 'handoff_drain_failed':
				return $this->prepareHandoffDrainFailed(notification: $notification, l: $l);
			case 'scheduled_report_delivered':
				return $this->prepareScheduledReportDelivered(notification: $notification, l: $l);
			case 'scheduled_report_failed':
				return $this->prepareScheduledReportFailed(notification: $notification, l: $l);
			case 'delegation_consent_requested':
				return $this->prepareDelegationConsentRequested(notification: $notification, l: $l);
			case 'credential_relink_needed':
				return $this->prepareCredentialRelinkNeeded(notification: $notification, l: $l);
			default:
				// Unknown subject. Object-lifecycle subjects
				// (object_created / object_updated / object_transitioned)
				// are rendered by AnnotationNotifier, not here.
				throw new UnknownNotificationException('Unknown subject');
		}//end switch
	}//end prepare()

	/**
	 * Prepare configuration update notification.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function prepareConfigurationUpdate(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();

		$configurationTitle = $parameters['configurationTitle'] ?? 'Configuration';
		$currentVersion = $parameters['currentVersion'] ?? 'unknown';
		$newVersion = $parameters['newVersion'] ?? 'unknown';

		$notification->setParsedSubject(
			$l->t('Configuration update available: %s', [$configurationTitle])
		);

		$notification->setParsedMessage(
			$l->t(
				'A new version (%s) of configuration "%s" is available. Current version: %s',
				[$newVersion, $configurationTitle, $currentVersion]
			)
		);

		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		// Add action to view the configuration.
		if (($parameters['configurationId'] ?? null) !== null) {
			$action = $notification->createAction();
			$action->setLabel($l->t('View'))
				->setPrimary(true)
				->setLink(
					link: $this->urlGenerator->linkToRouteAbsolute(
						routeName: 'openregister.dashboard.page'
					) . '#/configurations/' . $parameters['configurationId'],
					requestType: 'GET'
				);

			$notification->addAction($action);
		}

		return $notification;
	}//end prepareConfigurationUpdate()

	/**
	 * Prepare the queue-mode handoff drain-failure notification (ADR-051):
	 * a parked handoff could not execute when a provider appeared — the
	 * requester lost create permission or the mapped object failed target
	 * validation. The requester is informed so the parked work is never
	 * silently lost.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	private function prepareHandoffDrainFailed(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();

		$handoffId = $parameters['handoffId'] ?? 'handoff';
		$targetKind = $parameters['targetKind'] ?? '';
		$status = $parameters['status'] ?? 'failed';

		$notification->setParsedSubject(
			$l->t('Queued handoff "%s" could not be executed', [$handoffId])
		);

		$reason = $l->t('The target schema rejected the converted object.');
		if ($status === 'failed-permission') {
			$reason = $l->t('You no longer have permission to create objects in the providing schema.');
		}

		$notification->setParsedMessage(
			$l->t(
				'Your queued handoff to "%s" was attempted when a provider became available, but failed: %s',
				[$targetKind, $reason]
			)
		);

		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		return $notification;
	}//end prepareHandoffDrainFailed()

	/**
	 * Render a connected account whose grant the provider has revoked.
	 *
	 * THIS CASE IS WHAT MAKES THE NOTIFICATION REAL. `IManager::notify()` accepts a
	 * subject no notifier prepares and then drops it on the way to the screen, so a
	 * relink announcement without an arm here would fire, be logged as sent, and be
	 * seen by nobody. That is the shape of failure the whole relink lifecycle exists
	 * to avoid: a connection that quietly stops working.
	 *
	 * Every word is server-authored, and the provider is a catalogue identifier
	 * rather than anything a person typed. The message says what stopped, why it
	 * cannot fix itself, and what to do, because a person meeting this in a
	 * notification list has no other context for it.
	 *
	 * There is deliberately NO action button. Reconnecting means being sent to the
	 * provider's own consent screen, which is a `POST` returning a URL to follow
	 * rather than a link a notification can carry; a button that appeared to do it
	 * and did not would be worse than the sentence that names the page.
	 *
	 * @param INotification $notification The notification to prepare.
	 * @param mixed         $l            The localization instance.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function prepareCredentialRelinkNeeded(INotification $notification, $l): INotification {
		$provider = (string)($notification->getSubjectParameters()['provider'] ?? '');

		$notification->setParsedSubject(
			$l->t('Reconnect your %s account', [$provider])
		);

		$notification->setParsedMessage(
			$l->t(
				'The connection to %s is no longer accepted by the provider, so anything using it has '
				. 'stopped. This cannot be repaired automatically: open Connected accounts in your '
				. 'personal settings and reconnect the account.',
				[$provider]
			)
		);

		$notification->setLink(
			$this->urlGenerator->linkToRouteAbsolute(
				'settings.PersonalSettings.index',
				['section' => 'additional']
			)
		);

		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		return $notification;
	}//end prepareCredentialRelinkNeeded()

	/**
	 * Render a request to act on somebody's behalf.
	 *
	 * 🔴 EVERY WORD HERE IS SERVER-AUTHORED. The only values interpolated are the
	 * two uids, both read from the grant record. The requester's stated reason is
	 * deliberately absent — see {@see \OCA\OpenRegister\Service\Delegation\DelegationNotifier}.
	 * A requester that could write into this sentence would be authoring the
	 * prompt that asks for its own privilege, and a person reading it would have
	 * no way to tell which half the system was vouching for.
	 *
	 * The two ACTIONS are the decision. They are deliberately not "OK" and
	 * "Cancel": a consent dialog whose buttons do not name the outcome is one
	 * people dismiss, and dismissing is not deciding.
	 *
	 * @param INotification $notification The notification to prepare.
	 * @param mixed         $l            The l10n factory.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function prepareDelegationConsentRequested(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();

		$principal = (string)($parameters['principal'] ?? '');
		$grantUuid = (string)($parameters['grantUuid'] ?? '');
		$who = $this->displayName(uid: $principal);

		// RICH FIRST, PARSED ALWAYS — both, and neither is optional.
		//
		// `setRichSubject` with a `user` parameter is what lets a client render
		// the requester as a real user reference: the display name a colleague
		// recognises, with an avatar and semantic markup a screen reader can
		// announce as a person rather than as a bare token. `setParsedSubject` is
		// the plain-text fallback every other surface reads — the email digest,
		// the OCS API, a client that does not do rich text. A notification with
		// only one of the two is either unreadable somewhere or unrenderable
		// somewhere, and NC's own `isValidParsed()` refuses the second case.
		//
		// The two say the SAME sentence. A rich subject that read differently
		// from its fallback would mean two people looking at the same security
		// decision on different clients were answering different questions.
		$notification->setRichSubject(
			$l->t('{requester} asks to act on your behalf'),
			[
				'requester' => [
					'type' => 'user',
					'id' => $principal,
					'name' => $who,
				],
			]
		);
		$notification->setParsedSubject(
			$l->t('%s asks to act on your behalf', [$who])
		);

		// WHAT ALLOWING MEANS, BEFORE THE BUTTONS. A consent prompt whose
		// consequence is not stated before the controls is one people answer by
		// reflex, and a reader using a screen reader meets the buttons in reading
		// order — so the sentence has to carry the whole decision on its own.
		//
		// Three facts, in the order a person needs them: what they gain, that it
		// is recorded, and that it is reversible.
		$message = $l->t(
			'If you allow this, %s may perform work using your permissions until the grant expires '
			. 'or you withdraw it. Anything they do will be recorded as done on your behalf. '
			. 'You can withdraw the grant at any time.',
			[$who]
		);
		$notification->setRichMessage(
			$l->t(
				'If you allow this, {requester} may perform work using your permissions until the grant '
				. 'expires or you withdraw it. Anything they do will be recorded as done on your behalf. '
				. 'You can withdraw the grant at any time.'
			),
			[
				'requester' => [
					'type' => 'user',
					'id' => $principal,
					'name' => $who,
				],
			]
		);
		$notification->setParsedMessage($message);

		$base = $this->urlGenerator->linkToRouteAbsolute(
			'openregister.delegation.answer',
			['uuid' => $grantUuid]
		);

		// The labels NAME THE OUTCOME rather than acknowledging the dialog.
		// "OK"/"Cancel" on a consent prompt is the shape people dismiss: neither
		// word says what happens, so the decision is made by muscle memory. Out
		// of context — which is how a screen reader reaches a button list — "OK"
		// is meaningless and "Allow" is not.
		$allow = $notification->createAction();
		$allow->setLabel('allow')
			->setParsedLabel($l->t('Allow'))
			->setLink($base . '?allow=1', 'POST')
			->setPrimary(true);
		$notification->addAction($allow);

		$deny = $notification->createAction();
		$deny->setLabel('deny')
			->setParsedLabel($l->t('Deny'))
			->setLink($base . '?allow=0', 'POST')
			->setPrimary(false);
		$notification->addAction($deny);

		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		return $notification;
	}//end prepareDelegationConsentRequested()

	/**
	 * Prepare the scheduled-report success notification (scheduled-report-jobs,
	 * extended by scheduled-report-email-delivery): a recurring export ran
	 * and was delivered to Nextcloud Files, email, or both, per the report's
	 * `deliveryMode`. Reuses this single subject for every mode — `mode` and
	 * `emailFailureReason` (set when Files succeeded but the email leg
	 * failed, i.e. `lastStatus: email_failed`) are optional parameters so no
	 * new subject was needed.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function prepareScheduledReportDelivered(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();

		$reportName = $parameters['reportName'] ?? 'Scheduled report';
		$folder = $parameters['folder'] ?? 'Reports/';
		$filename = $parameters['filename'] ?? '';
		$mode = $parameters['mode'] ?? 'files';
		$emailFailureReason = $parameters['emailFailureReason'] ?? null;

		$notification->setParsedSubject(
			$l->t('Scheduled report "%s" delivered', [$reportName])
		);

		$message = match ($mode) {
			'email' => $l->t('Your scheduled report "%s" ran and was emailed to its recipients.', [$reportName]),
			'both' => $l->t(
				'Your scheduled report "%s" ran, was saved to %s%s, and emailed to its recipients.',
				[$reportName, $folder, $filename]
			),
			default => $l->t(
				'Your scheduled report "%s" ran and was saved to %s%s',
				[$reportName, $folder, $filename]
			),
		};

		if (is_string($emailFailureReason) === true && $emailFailureReason !== '') {
			$message .= ' ' . $l->t('Note: email delivery failed (%s).', [$emailFailureReason]);
		}

		$notification->setParsedMessage($message);

		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		if ($mode !== 'email') {
			$action = $notification->createAction();
			$action->setLabel($l->t('Open Files'))
				->setPrimary(true)
				->setLink(
					link: $this->urlGenerator->linkToRouteAbsolute(
						routeName: 'files.view.index'
					) . '?dir=' . rawurlencode('/' . trim((string)$folder, '/')),
					requestType: 'GET'
				);

			$notification->addAction($action);
		}

		return $notification;
	}//end prepareScheduledReportDelivered()

	/**
	 * Prepare the scheduled-report failure notification (scheduled-report-jobs):
	 * a recurring export failed (row-cap exceeded or any other error) and was
	 * not retried automatically.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	private function prepareScheduledReportFailed(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();

		$reportName = $parameters['reportName'] ?? 'Scheduled report';
		$reason = $parameters['reason'] ?? 'Unknown error';

		$notification->setParsedSubject(
			$l->t('Scheduled report "%s" failed', [$reportName])
		);

		$notification->setParsedMessage(
			$l->t('Your scheduled report "%s" could not be generated: %s', [$reportName, $reason])
		);

		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		return $notification;
	}//end prepareScheduledReportFailed()
}//end class
