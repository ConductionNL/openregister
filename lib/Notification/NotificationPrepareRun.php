<?php

/**
 * NotificationPrepareRun — one notification being prepared for one recipient locale.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notification;

use OCP\IL10N;
use OCP\Notification\INotification;

/**
 * The notification, its localiser and locale, and the action buttons rendered so far.
 *
 * Built by AnnotationNotifier::prepare() and never handed out of it.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationPrepareRun {
	/**
	 * The number of declared action buttons rendered so far.
	 *
	 * @var int
	 */
	public int $rendered = 0;

	/**
	 * Open the run.
	 *
	 * @param INotification $notification The notification being prepared.
	 * @param IL10N         $l            The recipient's localiser.
	 * @param string        $languageCode The recipient's locale.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		public readonly INotification $notification,
		public readonly IL10N $l,
		public readonly string $languageCode,
	) {
	}//end __construct()
}//end class
