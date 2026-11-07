<?php

/**
 * OpenRegister Flow Messaging Recipients
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

use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceServiceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolverInterface;
use OCA\OpenRegister\Notifications\Service\Notification\RateLimiterInterface;
use OCP\IUserManager;

/**
 * Who a flow send reaches: the recipient resolver, their preferences, the shared per-recipient budget and the acting user.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final readonly class FlowMessagingRecipients {

	/**
	 * Bundle the collaborators.
	 *
	 * @param NotificationRecipientResolverInterface $recipientResolver The subsystem's recipient resolver.
	 * @param NotificationPreferenceServiceInterface $preferences The recipient preference resolver.
	 * @param RateLimiterInterface $rateLimiter The subsystem's rate limiter (shared per-recipient budget).
	 * @param IUserManager $userManager Resolves the acting user.
	 */
	public function __construct(
		public NotificationRecipientResolverInterface $recipientResolver,
		public NotificationPreferenceServiceInterface $preferences,
		public RateLimiterInterface $rateLimiter,
		public IUserManager $userManager,
	) {
	}//end __construct()
}//end class
