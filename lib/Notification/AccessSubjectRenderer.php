<?php

/**
 * Renders the notifications about access, accounts and audit exports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Notification;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;

/**
 * Security settings, record ownership, connected accounts, audit exports and expiring grants.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
class AccessSubjectRenderer extends SubjectRenderer {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator URL generator for notification icons and actions
	 * @param DisplayNames $names Names a new owner
	 */
	public function __construct(IURLGenerator $urlGenerator, private readonly DisplayNames $names) {
		parent::__construct(urlGenerator: $urlGenerator);
	}//end __construct()

	/**
	 * Render a notification about access.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another subject
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		return match ($notification->getSubject()) {
			'security_setting_changed' => $this->securitySettingChanged(notification: $notification, l: $l),
			'object_ownership_changed' => $this->ownershipChanged(notification: $notification, l: $l),
			'credential_relink_needed' => $this->credentialRelinkNeeded(notification: $notification, l: $l),
			'audit_export_ready' => $this->auditExportReady(notification: $notification, l: $l),
			'external_grant_lapsing' => $this->externalGrantLapsing(notification: $notification, l: $l),
			default => null,
		};
	}//end render()

	/**
	 * Render "a security setting changed".
	 *
	 * WITHOUT THIS CASE THE ANNOUNCEMENT NEVER RENDERS: an unknown subject
	 * throws out of prepare(), so the beheerteam would be told nothing at the
	 * one moment REQ-ATS-004 exists for.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function securitySettingChanged(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$label = $this->settingLabel(parameters: $parameters);

		$notification->setParsedSubject($l->t('A security setting changed: %1$s', [$label]));
		$notification->setParsedMessage($this->securitySettingMessage(parameters: $parameters, l: $l));

		return $this->withAppIcon(notification: $notification);
	}//end securitySettingChanged()

	/**
	 * The name of a changed setting.
	 *
	 * @param array<string, mixed> $parameters The notification's subject parameters
	 *
	 * @return string The label, or else the setting's key
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function settingLabel(array $parameters): string {
		return (string) ($parameters['label'] ?? ($parameters['setting'] ?? ''));
	}//end settingLabel()

	/**
	 * Who changed which setting, from what to what.
	 *
	 * A secret takes the other branch and NEITHER value is shown. It is not
	 * masked here: the announcer never puts a secret in the parameters at all,
	 * because Nextcloud stores those in its database and can mail them.
	 *
	 * @param array<string, mixed> $parameters The notification's subject parameters
	 * @param IL10N $l The localization instance
	 *
	 * @return string The message
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function securitySettingMessage(array $parameters, IL10N $l): string {
		$label = $this->settingLabel(parameters: $parameters);
		$actor = (string) ($parameters['actor'] ?? '');

		if (($parameters['secret'] ?? false) === true) {
			return $l->t(
				'%1$s changed %2$s. It holds a secret, so neither value is shown here. Open the settings and put it back if nobody planned this.',
				[$actor, $label]
			);
		}

		return $l->t(
			'%1$s changed %2$s from "%3$s" to "%4$s". Open the settings and put it back if nobody planned this.',
			[$actor, $label, (string) ($parameters['oldValue'] ?? ''), (string) ($parameters['newValue'] ?? '')]
		);
	}//end securitySettingMessage()

	/**
	 * Render "a record you owned changed hands".
	 *
	 * WITHOUT THIS CASE THE NOTIFICATION NEVER RENDERS: an unknown subject
	 * throws out of prepare(), so the previous owner would be told nothing, which
	 * is the silent handover this capability exists to fix.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	private function ownershipChanged(INotification $notification, IL10N $l): INotification {
		$notification->setParsedSubject($l->t('A record you owned changed hands'));
		$notification->setParsedMessage($this->ownershipMessage(parameters: $notification->getSubjectParameters(), l: $l));

		return $this->withAppIcon(notification: $notification);
	}//end ownershipChanged()

	/**
	 * Who owns the record now, when that is known.
	 *
	 * @param array<string, mixed> $parameters The notification's subject parameters
	 * @param IL10N $l The localization instance
	 *
	 * @return string The message
	 *
	 * @spec openspec/specs/object-ownership/spec.md
	 */
	private function ownershipMessage(array $parameters, IL10N $l): string {
		$objectTitle = (string) ($parameters['objectTitle'] ?? '');
		$newOwner = (string) ($parameters['newOwner'] ?? '');

		if ($newOwner === '') {
			return $l->t('"%1$s" has a new owner. You are no longer answerable for it.', [$objectTitle]);
		}

		return $l->t(
			'%1$s is now the owner of "%2$s". You are no longer answerable for it. Ask them if that was not the plan.',
			[$this->names->forUid(uid: $newOwner), $objectTitle]
		);
	}//end ownershipMessage()

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
	 * @param IL10N $l The localization instance.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function credentialRelinkNeeded(INotification $notification, IL10N $l): INotification {
		$provider = (string)($notification->getSubjectParameters()['provider'] ?? '');

		$notification->setParsedSubject($l->t('Reconnect your %s account', [$provider]));
		$notification->setParsedMessage(
			$l->t(
				'The connection to %s is no longer accepted by the provider, so anything using it has '
				. 'stopped. This cannot be repaired automatically: open Connected accounts in your '
				. 'user settings and reconnect the account.',
				[$provider]
			)
		);

		// The connected accounts live in OpenRegister's own user settings dialog.
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('openregister.dashboard.page') . '#user-settings');

		return $this->withAppIcon(notification: $notification);
	}//end credentialRelinkNeeded()

	/**
	 * Render a large audit trail export that AuditTrailExportJob saved to the requester's Files.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
	 */
	private function auditExportReady(INotification $notification, IL10N $l): INotification {
		$parameters = $notification->getSubjectParameters();
		$folder = (string)($parameters['folder'] ?? 'Audit exports/');
		$filename = (string)($parameters['filename'] ?? '');
		$rows = (string)($parameters['rows'] ?? '0');

		$notification->setParsedSubject($l->t('Audit trail export ready'));
		$notification->setParsedMessage($l->t('Your audit trail export of %1$s entries was saved to %2$s%3$s.', [$rows, $folder, $filename]));
		$this->withAppIcon(notification: $notification);

		$url = $this->urlGenerator->linkToRouteAbsolute(routeName: 'files.view.index') . '?dir=' . rawurlencode('/' . trim($folder, '/'));
		$open = $this->primaryAction(notification: $notification, label: $l->t('Open Files'));
		$notification->addParsedAction($open->setLink(link: $url, requestType: 'GET'));

		return $notification;
	}//end auditExportReady()

	/**
	 * Render "your access ends soon" for the holder of an external grant.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification The prepared notification
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function externalGrantLapsing(INotification $notification, IL10N $l): INotification {
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
	}//end externalGrantLapsing()
}//end class
