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

		return (new SubjectRenderers(urlGenerator: $this->urlGenerator, userManager: $this->userManager))->render(notification: $notification, l: $l);
	}//end prepare()
}//end class
