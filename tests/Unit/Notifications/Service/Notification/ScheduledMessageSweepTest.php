<?php

/**
 * Unit tests for ScheduledMessageSweep: due messages are claimed with a
 * compare-and-set and sent once; cancelled, held and spent ones are not.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\ClaimAttempt;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessagePolicy;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageStoreInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageSweep;
use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCA\OpenRegister\Service\Integration\Providers\OutboundMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageSweep
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessagePolicy
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ClaimAttempt
 * @uses   \OCA\OpenRegister\Service\Integration\Providers\OutboundMessage
 */
class ScheduledMessageSweepTest extends TestCase {

	private const NOW = '2026-11-16T08:00:00+00:00';

	/**
	 * What the dispatcher was asked to send.
	 *
	 * @var array<int, array{source: string, path: string, body: array<mixed>}>
	 */
	private array $dispatched = [];

	/**
	 * An in-memory store that honours the compare-and-set.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return ScheduledMessageStoreInterface&object{rows: array<string, array<string, mixed>>}
	 */
	private function store(array $rows): ScheduledMessageStoreInterface {
		return new class ($rows) implements ScheduledMessageStoreInterface {
			/**
			 * @var array<string, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * Rows a stale reader holds, served instead of the current ones.
			 *
			 * @var array<int, array<string, mixed>>|null
			 */
			public ?array $snapshot = null;

			public function __construct(array $rows) {
				foreach ($rows as $row) {
					$this->rows[(string)$row['id']] = $row;
				}
			}

			public function open(int $limit): array {
				$rows = ($this->snapshot ?? array_values($this->rows));
				return array_slice(array_values(array_filter($rows, static fn (array $r): bool => in_array(($r['state'] ?? 'pending'), ['pending', 'claimed'], true))), 0, $limit);
			}

			public function compareAndSet(array $expect, array $set): bool {
				$row = ($this->rows[$expect['id']] ?? null);
				if ($row === null || ($row['state'] ?? 'pending') !== $expect['state'] || (int)($row['attempts'] ?? 0) !== $expect['attempts']) {
					return false;
				}

				$this->rows[$expect['id']] = array_merge($row, $set);
				return true;
			}

			public function record(string $id, array $fields): void {
				$this->rows[$id] = array_merge($this->rows[$id], $fields);
			}
		};
	}//end store()

	/**
	 * A dispatcher answering as given, recording each call.
	 *
	 * @param array<string, mixed>|RuntimeException $answer What it answers or throws.
	 *
	 * @return MessageDispatcherInterface
	 */
	private function dispatcher(array|RuntimeException $answer): MessageDispatcherInterface {
		$dispatcher = $this->createMock(MessageDispatcherInterface::class);
		$dispatcher->method('dispatch')->willReturnCallback(function (string $source, OutboundMessage $message) use ($answer): array {
			$this->dispatched[] = ['source' => $source, 'path' => $message->path, 'body' => $message->body];
			if ($answer instanceof RuntimeException) {
				throw $answer;
			}

			return $answer;
		});

		return $dispatcher;
	}//end dispatcher()

	private function attempt(string $worker = 'w1'): ClaimAttempt {
		return new ClaimAttempt(worker: $worker, at: new DateTimeImmutable(self::NOW));
	}//end attempt()

	private static function row(array $extra = []): array {
		return $extra + [
			'id' => '1',
			'state' => 'pending',
			'attempts' => 0,
			'sendAt' => '2026-11-16T07:59:00+00:00',
			'source' => 'twilio-sms',
			'path' => 'Messages.json',
			'body' => ['To' => '+31600000000', 'Body' => 'Uw afspraak'],
			'headers' => [],
			'messageId' => '<m1@or>',
		];
	}//end row()

	public function testADueMessageIsSentOnceAndRecorded(): void {
		$store = $this->store([self::row()]);
		$sweep = new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $this->dispatcher(['status' => 'sent', 'response' => ['sid' => 'SM1']]));

		$this->assertSame(['sent' => 1, 'failed' => 0, 'skipped' => 0], $sweep->run($this->attempt()));
		$this->assertSame([['source' => 'twilio-sms', 'path' => 'Messages.json', 'body' => ['To' => '+31600000000', 'Body' => 'Uw afspraak']]], $this->dispatched);
		$this->assertSame('sent', $store->rows['1']['state']);
		$this->assertSame(1, $store->rows['1']['attempts']);
		$this->assertSame('<m1@or>', $store->rows['1']['messageId']);
		$this->assertSame(['sid' => 'SM1'], $store->rows['1']['response']);

		$this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], $sweep->run($this->attempt()), 'a sent message is not opened again');
		$this->assertCount(1, $this->dispatched);
	}

	public function testTwoPassesReadingTheSameRowSendOnce(): void {
		$store = $this->store([self::row()]);
		$store->snapshot = [self::row()];
		$dispatcher = $this->dispatcher(['status' => 'sent', 'response' => []]);

		$first  = (new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $dispatcher))->run($this->attempt('w1'));
		$second = (new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $dispatcher))->run($this->attempt('w2'));

		$this->assertSame(1, $first['sent']);
		$this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 1], $second, 'the stale reader loses the compare-and-set');
		$this->assertCount(1, $this->dispatched);
	}

	public function testNotYetDueCancelledAndHeldMessagesAreSkipped(): void {
		$store = $this->store([
			self::row(['id' => '1', 'sendAt' => '2026-11-16T09:00:00+00:00']),
			self::row(['id' => '2', 'state' => 'cancelled']),
			self::row(['id' => '3', 'state' => 'claimed', 'attempts' => 1, 'claimedAt' => '2026-11-16T07:58:00+00:00']),
			self::row(['id' => '4', 'sendAt' => 'next monday-ish']),
		]);

		$tally = (new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $this->dispatcher(['status' => 'sent'])))->run($this->attempt());

		$this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 3], $tally, 'the cancelled row is never opened');
		$this->assertSame([], $this->dispatched);
	}

	public function testAStaleClaimIsTakenOver(): void {
		$store = $this->store([self::row(['state' => 'claimed', 'attempts' => 1, 'claimedBy' => 'dead', 'claimedAt' => '2026-11-16T07:00:00+00:00'])]);

		$tally = (new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $this->dispatcher(['status' => 'sent'])))->run($this->attempt('w2'));

		$this->assertSame(1, $tally['sent']);
		$this->assertSame('w2', $store->rows['1']['claimedBy']);
		$this->assertSame(2, $store->rows['1']['attempts']);
	}

	public function testADegradedSendGoesBackToPendingWithItsCause(): void {
		$store = $this->store([self::row()]);

		$tally = (new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $this->dispatcher(['unavailable' => true, 'cause' => 'upstream-service-down'])))->run($this->attempt());

		$this->assertSame(['sent' => 0, 'failed' => 1, 'skipped' => 0], $tally);
		$this->assertSame('pending', $store->rows['1']['state']);
		$this->assertSame('upstream-service-down', $store->rows['1']['lastError']);
		$this->assertSame(1, $store->rows['1']['attempts']);
	}

	public function testTheLastAttemptIsParkedNotRetried(): void {
		$store = $this->store([self::row(['attempts' => ScheduledMessagePolicy::MAX_ATTEMPTS - 1])]);

		(new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $this->dispatcher(new RuntimeException('smtp down'))))->run($this->attempt());

		$this->assertSame('parked', $store->rows['1']['state']);
		$this->assertSame('dispatch failed', $store->rows['1']['lastError']);
		$this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0], (new ScheduledMessageSweep($store, new ScheduledMessagePolicy(), $this->dispatcher(['status' => 'sent'])))->run($this->attempt()));
	}

	public function testOnePassTakesAtMostTheSweepLimit(): void {
		$rows = [];
		for ($i = 1; $i <= ScheduledMessagePolicy::SWEEP_LIMIT + 5; $i++) {
			$rows[] = self::row(['id' => (string)$i]);
		}

		$tally = (new ScheduledMessageSweep($this->store($rows), new ScheduledMessagePolicy(), $this->dispatcher(['status' => 'sent'])))->run($this->attempt());

		$this->assertSame(ScheduledMessagePolicy::SWEEP_LIMIT, $tally['sent']);
	}
}
