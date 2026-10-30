<?php

/**
 * The notifier registrations of OpenRegister.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notification;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the notifiers that render OpenRegister's notifications.
 *
 * Application::register() calls register() once.
 */
final class NotifierRegistrar {

	/**
	 * Register the notifiers.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context): void {
		// Register the annotation-driven INotifier so notifications fired by
		// AnnotationNotificationDispatcher get a parsed subject — without
		// this Nextcloud silently drops the notification.
		$context->registerNotifierService(AnnotationNotifier::class);

		// 🔴 AND the hand-written one, which was NEVER REGISTERED.
		//
		// `AnnotationNotifier::prepare()` deliberately re-throws
		// UnknownNotificationException for the subjects it does not own, and its
		// comment says they are "rendered by Notifier" — but nothing ever
		// registered Notifier, so nothing rendered them. Nextcloud drops a
		// notification no notifier claims, silently. Every
		// `configuration_update_available`, `handoff_drain_failed`,
		// `scheduled_report_delivered` and `scheduled_report_failed` this app has
		// ever dispatched was written to the store and then discarded at parse
		// time, and the class rendering them was written, complete, and
		// unreachable — the same orphaned-capability shape as
		// TriggerScheduleNode::validateConfig().
		$context->registerNotifierService(Notifier::class);
	}//end register()
}//end class
