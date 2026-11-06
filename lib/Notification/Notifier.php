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

use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCP\IL10N;
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
	 * @param NotificationTemplatesInterface|null $templates The platform's shipped
	 *                                       message templates. Consulted only for
	 *                                       an event whose words an administrator
	 *                                       has actually changed; nullable and
	 *                                       last for the same reason as above.
	 */
	public function __construct(
		private readonly IFactory $factory,
		private readonly IURLGenerator $urlGenerator,
		private readonly ?IUserManager $userManager = null,
		private readonly ?NotificationTemplatesInterface $templates = null,
	) {
	}//end __construct()

	/**
	 * Render this notification from an administrator's edited template, if any.
	 *
	 * Only an EDIT short-circuits the renderer. The shipped text is the same
	 * sentence the renderer already produces, and it carries the action links
	 * and the plural forms the registry's flat template cannot, so falling
	 * through to the renderer is the better answer whenever nobody has changed
	 * the words.
	 *
	 * @param INotification $notification The notification being prepared.
	 * @param string $languageCode The reader's language.
	 *
	 * @return INotification|null The prepared notification, or null when there is no edit.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	private function renderEditedTemplate(INotification $notification, string $languageCode): ?INotification {
		$rendered = $this->editedText(notification: $notification, languageCode: $languageCode);
		if ($rendered === null) {
			return null;
		}

		$notification->setParsedSubject($rendered['subject']);
		$notification->setParsedMessage($rendered['body']);
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('openregister', 'app-dark.svg'))
		);

		return $notification;
	}//end renderEditedTemplate()

	/**
	 * The text of this event's template, when an administrator changed it and it has a subject.
	 *
	 * @param INotification $notification The notification being prepared.
	 * @param string $languageCode The reader's language.
	 *
	 * @return array<string, string>|null The subject and body, or null when there is no edit.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	private function editedText(INotification $notification, string $languageCode): ?array {
		$event = $notification->getSubject();
		if ($this->isEdited(event: $event) === false) {
			return null;
		}

		$rendered = $this->templates->render(event: $event, locale: $languageCode, variables: $notification->getSubjectParameters());
		if ($rendered === null || $rendered['subject'] === '') {
			return null;
		}

		return $rendered;
	}//end editedText()

	/**
	 * Whether an administrator changed the words of this event's shipped template.
	 *
	 * @param string $event The event name.
	 *
	 * @return bool Whether the event has an edit.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	private function isEdited(string $event): bool {
		if ($this->templates === null || $this->templates->knows(event: $event) === false) {
			return false;
		}

		return $this->templates->hasEdit(event: $event);
	}//end isEdited()

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

		// An administrator's edit of this event's shipped template wins over the
		// renderers, which is what makes the shipped set editable rather than
		// merely present.
		$edited = $this->renderEditedTemplate(notification: $notification, languageCode: $languageCode);
		if ($edited !== null) {
			return $edited;
		}

		return $this->renderSubject(notification: $notification, l: $l);
	}//end prepare()

	/**
	 * Hand the notification to the renderer of its subject.
	 *
	 * Object lifecycle subjects (object_created, object_updated, object_transitioned)
	 * are rendered by AnnotationNotifier, so an unknown subject is routine.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 * @throws UnknownNotificationException If no renderer knows the subject
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function renderSubject(INotification $notification, IL10N $l): INotification {
		$renderers = [
			new ArchivalSubjectRenderer(urlGenerator: $this->urlGenerator),
			new ReportSubjectRenderer(urlGenerator: $this->urlGenerator),
			new WorkSubjectRenderer(urlGenerator: $this->urlGenerator),
		];
		foreach ($renderers as $renderer) {
			$rendered = $renderer->render(notification: $notification, l: $l);
			if ($rendered !== null) {
				return $rendered;
			}
		}

		$handler = match ($notification->getSubject()) {
			'audit_export_ready' => $this->prepareAuditExportReady(...),
			'delegation_consent_requested' => $this->prepareDelegationConsentRequested(...),
			'credential_relink_needed' => $this->prepareCredentialRelinkNeeded(...),
			'object_ownership_changed' => $this->prepareOwnershipChanged(...),
			'security_setting_changed' => $this->prepareSecuritySettingChanged(...),
			'external_grant_lapsing' => $this->prepareExternalGrantLapsing(...),
			default => throw new UnknownNotificationException('Unknown subject'),
		};

		return $handler(notification: $notification, l: $l);
	}//end renderSubject()

	/**
	 * Render "a security setting changed".
	 *
	 * WITHOUT THIS CASE THE ANNOUNCEMENT NEVER RENDERS: an unknown subject
	 * throws out of prepare(), so the beheerteam would be told nothing at the
	 * one moment REQ-ATS-004 exists for.
	 *
	 * A secret takes the other branch and NEITHER value is shown. It is not
	 * masked here: the announcer never puts a secret in the parameters at all,
	 * because Nextcloud stores those in its database and can mail them.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function prepareSecuritySettingChanged(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$label = (string) ($parameters['label'] ?? ($parameters['setting'] ?? ''));
		$actor = (string) ($parameters['actor'] ?? '');

		$notification->setParsedSubject($l->t('A security setting changed: %1$s', [$label]));

		if (($parameters['secret'] ?? false) === true) {
			$notification->setParsedMessage(
				$l->t(
					'%1$s changed %2$s. It holds a secret, so neither value is shown here. Open the settings and put it back if nobody planned this.',
					[$actor, $label]
				)
			);
		}

		if (($parameters['secret'] ?? false) !== true) {
			$notification->setParsedMessage(
				$l->t(
					'%1$s changed %2$s from "%3$s" to "%4$s". Open the settings and put it back if nobody planned this.',
					[$actor, $label, (string) ($parameters['oldValue'] ?? ''), (string) ($parameters['newValue'] ?? '')]
				)
			);
		}

		return $this->withAppIcon(notification: $notification);
	}//end prepareSecuritySettingChanged()

	/**
	 * Render "a record you owned changed hands".
	 *
	 * WITHOUT THIS CASE THE NOTIFICATION NEVER RENDERS: an unknown subject
	 * throws out of prepare(), so the previous owner would be told nothing — which
	 * is the silent handover this capability exists to fix.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	private function prepareOwnershipChanged(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$objectTitle = (string) ($parameters['objectTitle'] ?? '');
		$newOwner = (string) ($parameters['newOwner'] ?? '');

		$notification->setParsedSubject($l->t('A record you owned changed hands'));

		$notification->setParsedMessage(
			$l->t(
				'%1$s is now the owner of "%2$s". You are no longer answerable for it. Ask them if that was not the plan.',
				[$this->displayName(uid: $newOwner), $objectTitle]
			)
		);

		if ($newOwner === '') {
			$notification->setParsedMessage(
				$l->t('"%1$s" has a new owner. You are no longer answerable for it.', [$objectTitle])
			);
		}

		return $this->withAppIcon(notification: $notification);
	}//end prepareOwnershipChanged()

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
	 * @param mixed $l The localization instance.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
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
				. 'user settings and reconnect the account.',
				[$provider]
			)
		);

		// The connected accounts live in OpenRegister's own user settings dialog.
		$notification->setLink(
			$this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page') . '#user-settings'
		);

		return $this->withAppIcon(notification: $notification);
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
	 * @param mixed $l The l10n factory.
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

		return $this->withAppIcon(notification: $notification);
	}//end prepareDelegationConsentRequested()

	/**
	 * Prepare the notification for a large audit trail export that was saved
	 * to the requester's Files by AuditTrailExportJob.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed         $l            The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	private function prepareAuditExportReady(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$folder = (string)($parameters['folder'] ?? 'Audit exports/');
		$filename = (string)($parameters['filename'] ?? '');
		$rows = (string)($parameters['rows'] ?? '0');

		$notification->setParsedSubject($l->t('Audit trail export ready'));
		$notification->setParsedMessage(
			$l->t('Your audit trail export of %1$s entries was saved to %2$s%3$s.', [$rows, $folder, $filename])
		);
		$this->withAppIcon(notification: $notification);

		$action = $notification->createAction();
		$action->setLabel($l->t('Open Files'))
			->setPrimary(true)
			->setLink(
				link: $this->urlGenerator->linkToRouteAbsolute(routeName: 'files.view.index') . '?dir=' . rawurlencode('/' . trim($folder, '/')),
				requestType: 'GET'
			);
		$notification->addAction($action);

		return $notification;
	}//end prepareAuditExportReady()

	/**
	 * Set the app icon on a prepared notification.
	 *
	 * @param INotification $notification The notification being prepared
	 *
	 * @return INotification The same notification
	 */
	private function withAppIcon(INotification $notification): INotification {
		$notification->setIcon(
			$this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg')
		);

		return $notification;
	}//end withAppIcon()

	/**
	 * Render "your access ends soon" for the holder of an external grant.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param mixed $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function prepareExternalGrantLapsing(INotification $notification, $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$schema = (string)($parameters['schema'] ?? '');

		$notification->setParsedSubject($l->t('Your access to "%1$s" ends in %2$s days', [$schema, (string)($parameters['daysLeft'] ?? '')]));
		$notification->setParsedMessage(
			$l->t(
				'Your %1$s access ends on %2$s. Finish or hand over your work before then, or ask for an extension.',
				[(string)($parameters['action'] ?? ''), (string)($parameters['endsAt'] ?? '')]
			)
		);

		return $this->withAppIcon(notification: $notification);
	}//end prepareExternalGrantLapsing()
}//end class
