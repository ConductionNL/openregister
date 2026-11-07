<?php

/**
 * The notification and language a consent prompt is written into.
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
use OCP\Notification\INotification;

/**
 * Carries the notification and its language through the steps of one consent prompt.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
final class ConsentPromptDraft {

	/**
	 * Constructor.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 */
	public function __construct(
		public readonly INotification $notification,
		public readonly IL10N $l,
	) {
	}//end __construct()
}//end class
