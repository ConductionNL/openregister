<?php

/**
 * The email channel's composition/handoff, as a call-shared unit.
 *
 * Extracted from AnnotationNotificationDispatcher so both callers — the
 * declarative dispatcher and the flow messaging service — compose and hand a
 * transactional mail to the ONE mailer. The unit reports its outcome instead
 * of swallowing it, because the two callers disagree about what a failure
 * means: the declarative path stays best-effort (SMTP not configured is
 * normal in dev containers), while a flow send node turns a failed handoff
 * into a step failure — a COMPLETED run must never hide an undelivered
 * message.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\IUserManager;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Composes and hands off a transactional email to a Nextcloud user.
 */
class EmailSender {

	public const OUTCOME_DISPATCHED = 'dispatched';

	public const OUTCOME_KILL_SWITCH = 'kill-switch';

	public const OUTCOME_NO_ADDRESS = 'no-address';

	public const OUTCOME_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager User resolver for the recipient's address.
	 * @param IMailer $mailer The mailer.
	 * @param LoggerInterface $logger Logger for handoff diagnostics.
	 * @param NotificationChannelPolicy|null $channelPolicy The subsystem's per-channel kill switches; null means enabled.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IMailer $mailer,
		private readonly LoggerInterface $logger,
		private readonly ?NotificationChannelPolicy $channelPolicy = null,
	) {

	}//end __construct()

	/**
	 * Send a transactional email to a Nextcloud user.
	 *
	 * Resolves the user's email via IUserManager. Never throws: the outcome
	 * says what happened, and each caller decides what a non-delivery means —
	 * the declarative dispatcher logs and moves on, the flow messaging
	 * service escalates a failure into a step failure.
	 *
	 * @param string $uid Recipient user UID.
	 * @param string $subject Email subject line.
	 * @param string $body Email body text.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function send(string $uid, string $subject, string $body): string {
		if ($this->channelPolicy !== null && $this->channelPolicy->isChannelEnabled(channel: 'email') === false) {
			return self::OUTCOME_KILL_SWITCH;
		}

		try {
			$user = $this->userManager->get($uid);
			if ($user === null) {
				return self::OUTCOME_NO_ADDRESS;
			}

			$to = $user->getEMailAddress();
			if ($to === null || $to === '') {
				return self::OUTCOME_NO_ADDRESS;
			}

			$msg = $this->mailer->createMessage();
			$msg->setTo([$to => $user->getDisplayName()]);
			$msg->setSubject($subject);
			$msg->setPlainBody($body);
			$this->mailer->send($msg);
		} catch (\Throwable $e) {
			// SMTP not configured is normal in dev containers; the caller
			// decides whether this outcome escalates.
			$this->logger->debug(
				sprintf('[EmailSender] email to "%s" failed (%s)', $uid, $e->getMessage())
			);
			return self::OUTCOME_FAILED;
		}//end try

		return self::OUTCOME_DISPATCHED;
	}//end send()
}//end class
