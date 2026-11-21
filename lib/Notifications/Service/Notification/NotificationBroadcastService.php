<?php

/**
 * OpenRegister NotificationBroadcastService.
 *
 * One administered message to every user — a storingsmelding to the desk, a
 * maintenance window, a change of process. It is the one notification that has
 * no rule, no schema and no recipient list: the recipient is everybody.
 *
 * Delivery is a pull, not a fan-out. Writing a row per user at send time would
 * cost one write per account for a message most of them will read in the same
 * five minutes, and it would go stale the moment somebody joins. Instead the
 * broadcast is one row, and each person's first read writes one receipt. That
 * is what "each user receives it once" means here, and the unique index on
 * (broadcast, user) is what enforces it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use InvalidArgumentException;
use DateTime;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcast;
use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastMapper;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceiptMapper;
use Psr\Log\LoggerInterface;

/**
 * Sends, lists and acknowledges broadcasts.
 */
class NotificationBroadcastService {
	/**
	 * Constructor.
	 *
	 * @param NotificationBroadcastMapper $broadcasts The broadcast store.
	 * @param NotificationBroadcastReceiptMapper $receipts The per-user receipt store.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly NotificationBroadcastMapper $broadcasts,
		private readonly NotificationBroadcastReceiptMapper $receipts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one broadcast.
	 *
	 * @param BroadcastMessage $message The one-line subject and the longer text, when there is one.
	 * @param string           $sender  The uid of whoever is sending it.
	 * @param BroadcastWindow  $window  When it starts and stops showing.
	 *
	 * @return NotificationBroadcast The recorded broadcast.
	 *
	 * @throws InvalidArgumentException When the period ends before it starts, or the subject is empty.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function send(BroadcastMessage $message, string $sender, BroadcastWindow $window): NotificationBroadcast {
		return $this->sendBroadcast(outgoing: new OutgoingBroadcast(message: $message, sender: $sender, window: $window));
	}//end send()

	/**
	 * Send one broadcast as an administrator composed it.
	 *
	 * @param OutgoingBroadcast $outgoing The message, its sender and the window it shows in.
	 *
	 * @return NotificationBroadcast The recorded broadcast.
	 *
	 * @throws InvalidArgumentException When the period ends before it starts, or the subject is empty.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function sendBroadcast(OutgoingBroadcast $outgoing): NotificationBroadcast {
		$this->assertSendable(outgoing: $outgoing);
		$broadcast = $this->broadcasts->record(uuid: $this->newUuid(), outgoing: $outgoing);
		$this->logSent(outgoing: $outgoing);

		return $broadcast;
	}//end sendBroadcast()

	/**
	 * Refuse a broadcast without a subject or with a period that ends before it starts.
	 *
	 * A period that ends before it starts shows to nobody, which is the one
	 * outcome an administrator sending a broadcast cannot want.
	 *
	 * @param OutgoingBroadcast $outgoing The broadcast.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the period ends before it starts, or the subject is empty.
	 */
	private function assertSendable(OutgoingBroadcast $outgoing): void {
		if (trim($outgoing->message->subject) === '') {
			throw new InvalidArgumentException('A broadcast needs a subject.');
		}

		if ($outgoing->window->endsAt < $outgoing->window->startsAt) {
			throw new InvalidArgumentException('A broadcast cannot end before it starts.');
		}
	}//end assertSendable()

	/**
	 * Log a sent broadcast.
	 *
	 * @param OutgoingBroadcast $outgoing The broadcast.
	 *
	 * @return void
	 */
	private function logSent(OutgoingBroadcast $outgoing): void {
		$this->logger->info(
			sprintf(
				'[NotificationBroadcastService] "%s" sent by %s for %s to %s',
				$outgoing->message->subject,
				$outgoing->sender,
				$outgoing->window->startsAt->format(DateTime::ATOM),
				$outgoing->window->endsAt->format(DateTime::ATOM)
			)
		);
	}//end logSent()

	/**
	 * The broadcasts this user has not yet seen and that are showing now.
	 *
	 * @param string $userId The reader.
	 * @param DateTime|null $asOf The moment, defaulting to now.
	 *
	 * @return array<int, NotificationBroadcast> The rows, oldest first.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function activeFor(string $userId, ?DateTime $asOf = null): array {
		return $this->broadcasts->findUnseenFor(userId: $userId, asOf: $asOf);
	}//end activeFor()

	/**
	 * Record that a user has seen a broadcast, so it does not return to them.
	 *
	 * @param string $broadcastUuid The broadcast.
	 * @param string $userId The reader.
	 *
	 * @return boolean True when the broadcast exists and the receipt stands.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function acknowledge(string $broadcastUuid, string $userId): bool {
		if ($this->broadcasts->findByUuid(uuid: $broadcastUuid) === null) {
			return false;
		}

		$this->receipts->acknowledge(broadcastUuid: $broadcastUuid, userId: $userId);
		return true;
	}//end acknowledge()

	/**
	 * Every broadcast, newest first, with how many people have seen each.
	 *
	 * The count is the audit half of "recorded": a message that reached
	 * everybody is worth being able to say how far it actually got.
	 *
	 * @param int|null $limit Result limit.
	 * @param int|null $offset Result offset.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function listAll(?int $limit = null, ?int $offset = null): array {
		$rows = [];
		foreach ($this->broadcasts->findAllBroadcasts(limit: $limit, offset: $offset) as $broadcast) {
			$row = $broadcast->jsonSerialize();
			$row['seenBy'] = $this->receipts->countFor(broadcastUuid: (string)$broadcast->getUuid());
			$rows[] = $row;
		}

		return $rows;
	}//end listAll()

	/**
	 * Withdraw a broadcast.
	 *
	 * @param string $broadcastUuid The broadcast.
	 *
	 * @return boolean True when it was there and is now gone.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-broadcast-reaches-every-user-once-recorded-req-nrg-005
	 */
	public function withdraw(string $broadcastUuid): bool {
		return $this->broadcasts->deleteByUuid(uuid: $broadcastUuid);
	}//end withdraw()

	/**
	 * Mint a broadcast uuid.
	 *
	 * @return string A version-4 uuid.
	 */
	private function newUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newUuid()
}//end class
