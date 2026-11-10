<?php

/**
 * The per-recipient delivery channels a notification rule fans out over.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\Activity\IManager as IActivityManager;

/**
 * The per-recipient delivery channels of the declarative dispatcher.
 *
 * The in-app, email and Talk senders are the call-shared units the flow
 * messaging service invokes too; the activity manager publishes the
 * `activity` channel. Built by the container.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class ChannelSet {
	/**
	 * Constructor.
	 *
	 * @param NcNotificationSender $ncSender The nc-notification channel unit (web-push rides along).
	 * @param EmailSender          $emailSender The email channel unit.
	 * @param TalkSender           $talkSender The Talk channel unit.
	 * @param IActivityManager     $activityManager Publishes the `activity` channel.
	 */
	public function __construct(
		public NcNotificationSender $ncSender,
		public EmailSender $emailSender,
		public TalkSender $talkSender,
		public IActivityManager $activityManager,
	) {
	}//end __construct()
}//end class
