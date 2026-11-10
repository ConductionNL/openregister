<?php

/**
 * The web-push job queue the in-app channel rides along on.
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

use OCA\OpenRegister\Notifications\BackgroundJob\WebPushDispatchJob;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Enqueues one web-push dispatch job per real recipient; moved out of
 * NcNotificationSender, which still decides whether push may ride along.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class WebPushQueue {
	/**
	 * Constructor.
	 *
	 * @param IJobList          $jobList     Job list the web-push dispatch jobs go onto.
	 * @param LoggerInterface   $logger      Logger for a failed recipient check.
	 * @param IUserManager|null $userManager User resolver; null trusts the caller's recipients.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
		private readonly ?IUserManager $userManager = null,
	) {
	}//end __construct()

	/**
	 * Enqueue one job per enqueueable recipient.
	 *
	 * @param array<int, mixed>    $recipients Recipient uids.
	 * @param array<string, mixed> $payload    The job payload (rule, origin app, title, body, tag, actions).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function add(array $recipients, array $payload): void {
		foreach ($recipients as $uid) {
			if ($this->enqueueable(uid: $uid) === true) {
				$this->jobList->add(WebPushDispatchJob::class, ['uid' => $uid] + $payload);
			}
		}
	}//end add()

	/**
	 * Whether a recipient is a non-empty string naming a real user.
	 *
	 * @param mixed $uid Candidate uid.
	 *
	 * @return bool True when the uid may be enqueued.
	 */
	private function enqueueable(mixed $uid): bool {
		return is_string($uid) === true && $uid !== '' && $this->recipientExists(uid: $uid) === true;
	}//end enqueueable()

	/**
	 * Whether a uid names a real user; true when no user manager was injected
	 * (the caller has then already verified its recipients).
	 *
	 * @param string $uid Candidate uid.
	 *
	 * @return bool True when the uid may be enqueued.
	 */
	private function recipientExists(string $uid): bool {
		if ($this->userManager === null) {
			return true;
		}

		try {
			return $this->userManager->userExists($uid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf('[NcNotificationSender] userExists check failed for "%s": %s', $uid, $e->getMessage())
			);
			return false;
		}
	}//end recipientExists()
}//end class
