<?php

/**
 * What every renderer of an OpenRegister notification subject shares.
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
use OCP\Notification\IAction;
use OCP\Notification\INotification;

/**
 * Renders a family of notification subjects, and answers null for a subject it does not know.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
abstract class SubjectRenderer {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator URL generator for notification icons and actions
	 */
	public function __construct(protected readonly IURLGenerator $urlGenerator) {
	}//end __construct()

	/**
	 * Render a notification whose subject belongs to this renderer.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another renderer's subject
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	abstract public function render(INotification $notification, IL10N $l): ?INotification;

	/**
	 * A new primary action on the notification, labelled; the caller sets its link and adds it as parsed.
	 *
	 * @param INotification $notification The notification being prepared
	 * @param string $label The action label
	 *
	 * @return IAction The action
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	protected function primaryAction(INotification $notification, string $label): IAction {
		return $notification->createAction()->setParsedLabel($label)->setPrimary(true);
	}//end primaryAction()

	/**
	 * Set the app icon on a prepared notification.
	 *
	 * Nextcloud refuses an icon that is not an absolute URL.
	 *
	 * @param INotification $notification The notification being prepared
	 *
	 * @return INotification The same notification
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	protected function withAppIcon(INotification $notification): INotification {
		$notification->setIcon(
			$this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(appName: 'openregister', file: 'app.svg'))
		);

		return $notification;
	}//end withAppIcon()
}//end class
