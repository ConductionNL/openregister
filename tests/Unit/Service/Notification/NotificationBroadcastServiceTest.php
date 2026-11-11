<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTime;
use OCA\OpenRegister\Notifications\Db\BroadcastMessage;
use OCA\OpenRegister\Notifications\Db\BroadcastWindow;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcast;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastMapper;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceipt;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcastReceiptMapper;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationBroadcastService;
use OCA\OpenRegister\Notifications\Service\Notification\OutgoingBroadcast;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the one message that reaches everybody: that it is recorded with its
 * sender, that each person gets it once, and that a period which shows to
 * nobody is refused rather than accepted quietly.
 */
class NotificationBroadcastServiceTest extends TestCase {
	private NotificationBroadcastMapper&MockObject $broadcasts;
	private NotificationBroadcastReceiptMapper&MockObject $receipts;
	private LoggerInterface&MockObject $logger;
	private NotificationBroadcastService $service;

	/**
	 * The broadcasts this fake store holds, keyed by uuid.
	 *
	 * @var array<string, NotificationBroadcast>
	 */
	private array $stored = [];

	/**
	 * The receipts this fake store holds, keyed by "uuid|uid".
	 *
	 * @var array<string, NotificationBroadcastReceipt>
	 */
	private array $seen = [];

	protected function setUp(): void {
		parent::setUp();
		$this->broadcasts = $this->createMock(NotificationBroadcastMapper::class);
		$this->receipts = $this->createMock(NotificationBroadcastReceiptMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->broadcasts->method('record')->willReturnCallback(
			function (string $uuid, OutgoingBroadcast $outgoing): NotificationBroadcast {
				$entity = new NotificationBroadcast();
				$entity->setUuid($uuid);
				$entity->setSubject($outgoing->message->subject);
				$entity->setBody($outgoing->message->body);
				$entity->setSender($outgoing->sender);
				$entity->setStartsAt($outgoing->window->startsAt);
				$entity->setEndsAt($outgoing->window->endsAt);
				$entity->setCreated(new DateTime());
				$this->stored[$uuid] = $entity;

				return $entity;
			}
		);
		$this->broadcasts->method('findByUuid')->willReturnCallback(
			fn (string $uuid): ?NotificationBroadcast => ($this->stored[$uuid] ?? null)
		);
		$this->broadcasts->method('findUnseenFor')->willReturnCallback(
			function (string $userId, ?DateTime $asOf = null): array {
				$moment = ($asOf ?? new DateTime());
				$rows = [];
				foreach ($this->stored as $uuid => $broadcast) {
					if ($broadcast->isActiveAt($moment) === false) {
						continue;
					}

					if (isset($this->seen[$uuid . '|' . $userId]) === true) {
						continue;
					}

					$rows[] = $broadcast;
				}

				return $rows;
			}
		);
		$this->receipts->method('acknowledge')->willReturnCallback(
			function (string $broadcastUuid, string $userId, ?DateTime $seenAt = null): NotificationBroadcastReceipt {
				$key = $broadcastUuid . '|' . $userId;
				if (isset($this->seen[$key]) === true) {
					return $this->seen[$key];
				}

				$receipt = new NotificationBroadcastReceipt();
				$receipt->setBroadcastUuid($broadcastUuid);
				$receipt->setUserId($userId);
				$receipt->setSeenAt(($seenAt ?? new DateTime()));
				$this->seen[$key] = $receipt;

				return $receipt;
			}
		);

		$this->service = new NotificationBroadcastService(
			$this->broadcasts,
			$this->receipts,
			$this->logger
		);
	}

	/**
	 * Send one for today.
	 *
	 * @param string $subject The message.
	 * @param string $sender Who sends it.
	 *
	 * @return NotificationBroadcast The recorded broadcast.
	 */
	private function sendToday(string $subject = 'Storing in het zaaksysteem', string $sender = 'beheerder'): NotificationBroadcast {
		return $this->service->send(
			message: new BroadcastMessage(subject: $subject, body: 'We werken eraan.'),
			sender: $sender,
			window: new BroadcastWindow(startsAt: new DateTime('-1 hour'), endsAt: new DateTime('+1 hour'))
		);
	}

	/**
	 * The record names the sender and holds the period.
	 */
	public function testTheRecordNamesTheSender(): void {
		$broadcast = $this->sendToday();

		$this->assertSame('beheerder', $broadcast->getSender());
		$this->assertSame('Storing in het zaaksysteem', $broadcast->getSubject());
		$this->assertTrue($broadcast->isActiveAt());
	}

	/**
	 * Each user sees it once: after acknowledging, it does not come back.
	 */
	public function testEachUserSeesItOnce(): void {
		$broadcast = $this->sendToday();

		$this->assertCount(1, $this->service->activeFor(userId: 'anna'));
		$this->service->acknowledge(broadcastUuid: (string)$broadcast->getUuid(), userId: 'anna');
		$this->assertCount(0, $this->service->activeFor(userId: 'anna'));
	}

	/**
	 * One person's receipt is theirs: it does not take the message away from
	 * anybody else.
	 */
	public function testOnePersonsReceiptDoesNotSilenceAnother(): void {
		$broadcast = $this->sendToday();
		$this->service->acknowledge(broadcastUuid: (string)$broadcast->getUuid(), userId: 'anna');

		$this->assertCount(1, $this->service->activeFor(userId: 'bram'));
	}

	/**
	 * Acknowledging twice is acknowledging once.
	 */
	public function testAcknowledgingTwiceIsIdempotent(): void {
		$broadcast = $this->sendToday();
		$uuid = (string)$broadcast->getUuid();

		$this->assertTrue($this->service->acknowledge(broadcastUuid: $uuid, userId: 'anna'));
		$this->assertTrue($this->service->acknowledge(broadcastUuid: $uuid, userId: 'anna'));
		$this->assertCount(1, $this->seen);
	}

	/**
	 * A broadcast outside its period shows to nobody.
	 */
	public function testABroadcastOutsideItsPeriodDoesNotShow(): void {
		$this->service->send(
			message: new BroadcastMessage(subject: 'Onderhoud vannacht', body: null),
			sender: 'beheerder',
			window: new BroadcastWindow(startsAt: new DateTime('+2 days'), endsAt: new DateTime('+3 days'))
		);

		$this->assertCount(0, $this->service->activeFor(userId: 'anna'));
	}

	/**
	 * A period that ends before it starts shows to nobody, which is the one
	 * outcome an administrator sending a broadcast cannot have meant.
	 */
	public function testAPeriodEndingBeforeItStartsIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->send(
			message: new BroadcastMessage(subject: 'Onmogelijk', body: null),
			sender: 'beheerder',
			window: new BroadcastWindow(startsAt: new DateTime('+2 days'), endsAt: new DateTime('+1 day'))
		);
	}

	/**
	 * A broadcast with nothing to say is refused.
	 */
	public function testAnEmptySubjectIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->send(
			message: new BroadcastMessage(subject: '   ', body: 'Wel een body.'),
			sender: 'beheerder',
			window: new BroadcastWindow(startsAt: new DateTime(), endsAt: new DateTime('+1 hour'))
		);
	}

	/**
	 * Acknowledging something that is not there is refused, not recorded.
	 */
	public function testAcknowledgingAnUnknownBroadcastIsRefused(): void {
		$this->assertFalse($this->service->acknowledge(broadcastUuid: 'no-such-uuid', userId: 'anna'));
		$this->assertCount(0, $this->seen);
	}

	/**
	 * Two broadcasts get two identifiers; a shared one would make one
	 * acknowledgement silence both.
	 */
	public function testEachBroadcastGetsItsOwnIdentifier(): void {
		$one = $this->sendToday(subject: 'Eerste');
		$two = $this->sendToday(subject: 'Tweede');

		$this->assertNotSame($one->getUuid(), $two->getUuid());
	}
}
