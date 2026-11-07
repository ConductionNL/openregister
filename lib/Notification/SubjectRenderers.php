<?php

/**
 * Hands a notification to the renderer of its subject.
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
use OCP\IUserManager;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;

/**
 * Every renderer of this app's subjects.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
class SubjectRenderers {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator URL generator for notification icons and actions
	 * @param IUserManager|null $userManager Resolves a uid to a display name
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly ?IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Render a notification with the renderer that knows its subject.
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
	public function render(INotification $notification, IL10N $l): INotification {
		foreach ($this->all() as $renderer) {
			$rendered = $renderer->render(notification: $notification, l: $l);
			if ($rendered !== null) {
				return $rendered;
			}
		}

		throw new UnknownNotificationException('Unknown subject');
	}//end render()

	/**
	 * The renderer of each family of subjects.
	 *
	 * @return array<int, SubjectRenderer> The renderers
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function all(): array {
		$names = new DisplayNames(userManager: $this->userManager);

		return [
			new ArchivalSubjectRenderer(urlGenerator: $this->urlGenerator),
			new ReportSubjectRenderer(urlGenerator: $this->urlGenerator),
			new WorkSubjectRenderer(urlGenerator: $this->urlGenerator),
			new AccessSubjectRenderer(urlGenerator: $this->urlGenerator, names: $names),
			new DelegationConsentRenderer(urlGenerator: $this->urlGenerator, names: $names),
		];
	}//end all()
}//end class
