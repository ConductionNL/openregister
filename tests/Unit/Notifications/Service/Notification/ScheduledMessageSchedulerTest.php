<?php

/**
 * ScheduledMessageScheduler: schedule a send, list an object's messages, cancel one.
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
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageRefusal;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageScheduler;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageStoreInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageScheduler
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageRefusal
 */
class ScheduledMessageSchedulerTest extends TestCase {

	private const NOW = '2026-11-13T16:00:00+00:00';

	/** @var array<string, array<string, mixed>> The stored rows. */
	private array $rows = [];

	/** @var array<int, ObjectAuditEvent> The audited events. */
	private array $audited = [];

	/** @var bool Whether the next compare-and-set loses to a sweep. */
	private bool $loseTheRace = false;

	private function scheduler(string $caller = 'handler', bool $mayUpdate = false): ScheduledMessageScheduler {
		$test = $this;
		$store = new class ($test) implements ScheduledMessageStoreInterface {
			public function __construct(private ScheduledMessageSchedulerTest $test) {
			}

			public function open(int $limit): array {
				return [];
			}

			public function compareAndSet(array $expect, array $set): bool {
				return $this->test->storeCompareAndSet($expect, $set);
			}

			public function record(string $id, array $fields): void {
			}

			public function insert(array $message): string {
				return $this->test->storeInsert($message);
			}

			public function forObject(string $objectUuid, int $limit): array {
				return $this->test->storeForObject($objectUuid, $limit);
			}

			public function find(string $id): ?array {
				return $this->test->storeFind($id);
			}
		};

		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('mayManage')->willReturn($mayUpdate);
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willReturnCallback(
			function (ObjectAuditEvent $event): AuditTrail {
				$this->audited[] = $event;
				return new AuditTrail();
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ScheduledMessageScheduler($store, $visibility, $audit, $session, $this->createMock(LoggerInterface::class));
	}

	public function storeInsert(array $message): string {
		$id = (string)(count($this->rows) + 1);
		$this->rows[$id] = $message + ['id' => $id, 'state' => 'pending', 'attempts' => 0, 'messageId' => '', 'lastError' => ''];
		return $id;
	}

	public function storeForObject(string $objectUuid, int $limit): array {
		return array_slice(array_values(array_filter($this->rows, static fn (array $r): bool => ($r['objectUuid'] ?? '') === $objectUuid)), 0, $limit);
	}

	public function storeFind(string $id): ?array {
		return ($this->rows[$id] ?? null);
	}

	public function storeCompareAndSet(array $expect, array $set): bool {
		$row = ($this->rows[$expect['id']] ?? null);
		if ($this->loseTheRace === true) {
			$this->rows[$expect['id']]['state'] = 'sent';
			return false;
		}

		if ($row === null || $row['state'] !== $expect['state'] || (int)$row['attempts'] !== $expect['attempts']) {
			return false;
		}

		$this->rows[$expect['id']] = array_merge($row, $set);
		return true;
	}

	private static function object(string $uuid = 'case-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}

	private function scheduled(string $author = 'handler', string $object = 'case-1'): string {
		return $this->storeInsert(['channel' => 'sms', 'objectUuid' => $object, 'author' => $author, 'sendAt' => '2026-11-16T07:00:00+00:00']);
	}

	public function testAFutureSendIsStoredPendingBoundAndAudited(): void {
		$answer = $this->scheduler()->schedule(
			['channel' => 'sms', 'source' => 'twilio-sms', 'path' => 'Messages.json', 'body' => ['Body' => 'Hi'], 'headers' => [], 'sendAt' => '2026-11-16T08:00:00+01:00'],
			self::object(),
			new DateTimeImmutable(self::NOW)
		);

		$this->assertSame(['id' => '1', 'state' => 'pending', 'sendAt' => '2026-11-16T07:00:00+00:00', 'channel' => 'sms'], $answer);
		$this->assertSame(['case-1', 'handler', 'twilio-sms', ['Body' => 'Hi']], [$this->rows['1']['objectUuid'], $this->rows['1']['author'], $this->rows['1']['source'], $this->rows['1']['body']]);
		$this->assertCount(1, $this->audited);
		$this->assertSame('message.scheduled', $this->audited[0]->action);
		$this->assertSame(['message' => '1', 'channel' => 'sms', 'sendAt' => '2026-11-16T07:00:00+00:00'], $this->audited[0]->context);
	}

	public function testAnUnboundSendIsStoredWithoutAnAuditEntry(): void {
		$this->scheduler()->schedule(['channel' => 'sms', 'sendAt' => '2026-11-14T00:00:00+00:00'], null, new DateTimeImmutable(self::NOW));

		$this->assertNull($this->rows['1']['objectUuid']);
		$this->assertSame([], $this->audited);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusedMoments(): array {
		return [
			'in the past' => ['2026-11-13T15:59:59+00:00', 'sendAt must be in the future'],
			'now' => [self::NOW, 'sendAt must be in the future'],
			'not a date' => ['next monday-ish nine', 'sendAt must be a date and time, such as 2026-11-16T08:00:00+01:00'],
			'blank' => ['  ', 'sendAt must be a date and time, such as 2026-11-16T08:00:00+01:00'],
		];
	}

	/**
	 * @dataProvider refusedMoments
	 */
	public function testASendAtThatIsNotInTheFutureIsRefusedAndNothingIsStored(string $sendAt, string $message): void {
		try {
			$this->scheduler()->schedule(['channel' => 'sms', 'sendAt' => $sendAt], self::object(), new DateTimeImmutable(self::NOW));
			$this->fail('The sendAt must be refused.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->audited);
	}

	public function testTheListNamesTheObjectsMessagesWithoutTheirBodies(): void {
		$this->scheduled();
		$this->scheduled(object: 'case-2');

		$listed = $this->scheduler()->forObject(self::object());

		$this->assertSame(
			[['id' => '1', 'channel' => 'sms', 'state' => 'pending', 'sendAt' => '2026-11-16T07:00:00+00:00', 'author' => 'handler', 'attempts' => 0, 'messageId' => '', 'lastError' => '']],
			$listed
		);
	}

	public function testTheAuthorCancelsAndTheCancellationIsAudited(): void {
		$id = $this->scheduled(author: 'handler');

		$answer = $this->scheduler(caller: 'handler', mayUpdate: false)->cancel(self::object(), $id);

		$this->assertSame(['id' => $id, 'state' => 'cancelled'], $answer);
		$this->assertSame('cancelled', $this->rows[$id]['state']);
		$this->assertSame('message.cancelled', $this->audited[0]->action);
	}

	public function testAUserWhoMayUpdateTheObjectCancelsAnothersMessage(): void {
		$id = $this->scheduled(author: 'handler');

		$this->scheduler(caller: 'supervisor', mayUpdate: true)->cancel(self::object(), $id);

		$this->assertSame('cancelled', $this->rows[$id]['state']);
	}

	public function testAReaderWhoIsNotTheAuthorCannotCancel(): void {
		$id = $this->scheduled(author: 'handler');

		$refusal = $this->refusal(fn () => $this->scheduler(caller: 'reader', mayUpdate: false)->cancel(self::object(), $id));

		$this->assertSame(403, $refusal->status);
		$this->assertSame('pending', $this->rows[$id]['state']);
		$this->assertSame([], $this->audited);
	}

	public function testAMessageOfAnotherObjectIsNotFound(): void {
		$id = $this->scheduled(object: 'case-2');

		$this->assertSame(404, $this->refusal(fn () => $this->scheduler()->cancel(self::object('case-1'), $id))->status);
		$this->assertSame(404, $this->refusal(fn () => $this->scheduler()->cancel(self::object('case-1'), '99'))->status);
	}

	public function testASentMessageCannotBeCancelled(): void {
		$id = $this->scheduled();
		$this->rows[$id]['state'] = 'sent';

		$refusal = $this->refusal(fn () => $this->scheduler()->cancel(self::object(), $id));

		$this->assertSame([409, 'This message can no longer be cancelled: it reads sent'], [$refusal->status, $refusal->getMessage()]);
		$this->assertSame([], $this->audited);
	}

	public function testACancelThatLosesToTheSweepSaysWhatTheMessageNowReads(): void {
		$id = $this->scheduled();
		$this->loseTheRace = true;

		$refusal = $this->refusal(fn () => $this->scheduler()->cancel(self::object(), $id));

		$this->assertSame([409, 'This message can no longer be cancelled: it reads sent'], [$refusal->status, $refusal->getMessage()]);
		$this->assertSame([], $this->audited);
	}

	private function refusal(callable $act): ScheduledMessageRefusal {
		try {
			$act();
		} catch (ScheduledMessageRefusal $refusal) {
			return $refusal;
		}

		$this->fail('A refusal was expected.');
	}
}
