<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

use DateTime;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Db\RecipientNotice;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Marking a notice read is scoped to the recipient it is addressed to: the row id
 * alone never marks a row, and a notice of somebody else reports nothing marked.
 *
 * @covers \OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper
 */
class NotificationHistoryMarkReadAccessTest extends TestCase {

	/**
	 * Equality clauses the mapper put on the update, as column => bound value.
	 *
	 * @var array<string, mixed>
	 */
	private array $equals = [];

	/**
	 * Bound values by parameter object id.
	 *
	 * @var array<int, mixed>
	 */
	private array $bound = [];

	public function testTheOwnUnreadNoticeIsMarked(): void {
		$this->assertTrue($this->markRead(affected: 1, id: 7, recipient: 'alice'));
		$this->assertSame(['id' => 7, 'recipient' => 'alice'], $this->equals);
	}

	public function testANoticeOfSomebodyElseIsRefused(): void {
		$this->assertFalse($this->markRead(affected: 0, id: 7, recipient: 'mallory'));
		$this->assertSame(['id' => 7, 'recipient' => 'mallory'], $this->equals);
	}

	public function testTheRecipientIsAlwaysPartOfTheWhere(): void {
		$this->markRead(affected: 1, id: 9, recipient: '');
		$this->assertArrayHasKey('recipient', $this->equals);
		$this->assertSame('', $this->equals['recipient']);
	}

	private function markRead(int $affected, int $id, string $recipient): bool {
		$this->equals = [];
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value): string {
				$this->equals[$column] = is_object($value) === true ? ($this->bound[spl_object_id($value)] ?? null) : $value;
				return $column;
			}
		);
		$expr->method('isNull')->willReturn('read_at IS NULL');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('update')->willReturnSelf();
		$qb->method('set')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): IParameter {
				$parameter = $this->createMock(IParameter::class);
				$this->bound[spl_object_id($parameter)] = $value;
				return $parameter;
			}
		);
		$qb->method('executeStatement')->willReturn($affected);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return (new NotificationHistoryMapper($db))->markRead(new RecipientNotice($id, $recipient), new DateTime('2026-01-02T03:04:05+00:00'));
	}
}
