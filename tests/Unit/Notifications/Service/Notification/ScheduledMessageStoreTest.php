<?php

/**
 * Unit tests for ScheduledMessageStore: the claim compares id, state and
 * attempts and reports whether it wrote; rows come back in the policy's shape.
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

use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageStore
 */
class ScheduledMessageStoreTest extends TestCase {

	/**
	 * The conditions the query was given, in order.
	 *
	 * @var array<int, string>
	 */
	private array $where = [];

	/**
	 * The columns set, column => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * A store over a query builder that records what it was asked.
	 *
	 * @param int                              $affected What executeStatement answers.
	 * @param array<int, array<string, mixed>> $rows     What executeQuery yields.
	 *
	 * @return ScheduledMessageStore
	 */
	private function store(int $affected = 1, array $rows = []): ScheduledMessageStore {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($column, $value): string => $column . '=' . $value);
		$expr->method('in')->willReturnCallback(static fn ($column, $value): string => $column . ' in ' . $value);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => is_array($value) ? implode('|', $value) : (string)$value);
		foreach (['select', 'from', 'update', 'orderBy', 'setMaxResults'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('where')->willReturnCallback(function (string $condition) use ($qb): IQueryBuilder {
			$this->where[] = $condition;
			return $qb;
		});
		$qb->method('andWhere')->willReturnCallback(function (string $condition) use ($qb): IQueryBuilder {
			$this->where[] = $condition;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb): IQueryBuilder {
			$this->set[$column] = $value;
			return $qb;
		});
		$qb->method('executeStatement')->willReturn($affected);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new ScheduledMessageStore($db);
	}//end store()

	public function testTheClaimComparesIdStateAndAttempts(): void {
		$won = $this->store(1)->compareAndSet(
			['id' => '7', 'state' => 'pending', 'attempts' => 2],
			['state' => 'claimed', 'attempts' => 3, 'claimedBy' => 'w1', 'claimedAt' => '2026-11-16T08:00:00+00:00']
		);

		$this->assertTrue($won);
		$this->assertSame(['id=7', 'state=pending', 'attempts=2'], $this->where);
		$this->assertSame('claimed', $this->set['state']);
		$this->assertSame('3', $this->set['attempts']);
		$this->assertSame('w1', $this->set['claimed_by']);
		$this->assertArrayHasKey('updated', $this->set);
	}

	public function testAClaimThatChangedNoRowIsLost(): void {
		$this->assertFalse($this->store(0)->compareAndSet(['id' => '7', 'state' => 'pending', 'attempts' => 2], ['state' => 'claimed']));
	}

	public function testAnOutcomeWritesOnlyKnownColumnsAndEncodesTheResponse(): void {
		$this->store()->record('7', ['state' => 'sent', 'messageId' => '<m@or>', 'lastError' => '', 'response' => ['sid' => 'SM1'], 'object' => 'ignored']);

		$this->assertSame(['id=7'], $this->where);
		$this->assertSame('sent', $this->set['state']);
		$this->assertSame('<m@or>', $this->set['message_id']);
		$this->assertSame('', $this->set['last_error']);
		$this->assertSame('{"sid":"SM1"}', $this->set['response']);
		$this->assertArrayNotHasKey('object', $this->set);
	}

	public function testOpenRowsComeBackInThePolicysShape(): void {
		$rows = $this->store(1, [[
			'id' => 7, 'state' => 'claimed', 'attempts' => '2', 'send_at' => '2026-11-16T08:00:00+00:00', 'claimed_at' => '2026-11-16T08:00:05+00:00',
			'source' => 'twilio-sms', 'path' => 'Messages.json', 'body' => '{"Body":"Hi"}', 'headers' => 'not json', 'message_id' => null,
		]])->open(50);

		$this->assertSame([[
			'id' => '7', 'state' => 'claimed', 'attempts' => 2, 'sendAt' => '2026-11-16T08:00:00+00:00', 'claimedAt' => '2026-11-16T08:00:05+00:00',
			'source' => 'twilio-sms', 'path' => 'Messages.json', 'body' => ['Body' => 'Hi'], 'headers' => [], 'messageId' => '',
		]], $rows);
		$this->assertSame(['state in pending|claimed'], $this->where);
	}
}
