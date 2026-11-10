<?php

/**
 * OpenRegister Flow Messaging Channels
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicyInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSenderInterface;

/**
 * The channels a flow sends through: the per-channel kill switches and the in-app, email and Talk senders.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final readonly class FlowMessagingChannels {

	/**
	 * Bundle the collaborators.
	 *
	 * @param NotificationChannelPolicyInterface $channelPolicy The subsystem's per-channel kill switches.
	 * @param NcNotificationSenderInterface $ncSender The nc-notification channel sender (web-push rides along).
	 * @param EmailSenderInterface $emailSender The email channel sender.
	 * @param TalkSenderInterface $talkSender The Talk channel sender.
	 */
	public function __construct(
		public NotificationChannelPolicyInterface $channelPolicy,
		public NcNotificationSenderInterface $ncSender,
		public EmailSenderInterface $emailSender,
		public TalkSenderInterface $talkSender,
	) {
	}//end __construct()
}//end class
