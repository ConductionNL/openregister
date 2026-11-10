<?php

/**
 * Pins the page MessageMapper::findByConversation() asks the database for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class MessageMapperConversationPageTest extends TestCase {

	/**
	 * The paging calls the query builder received.
	 *
	 * @var array<string, mixed>
	 */
	private array $paging = [];

	public function testTheDefaultPageIsTheFirstHundredMessages(): void {
		$this->mapper()->findByConversation(42);

		$this->assertSame(['max' => 100, 'first' => 0], $this->paging);
	}//end testTheDefaultPageIsTheFirstHundredMessages()

	public function testAGivenPageIsPassedThrough(): void {
		$this->mapper()->findByConversation(42, new ListQuery(limit: 5, offset: 10));

		$this->assertSame(['max' => 5, 'first' => 10], $this->paging);
	}//end testAGivenPageIsPassedThrough()

	private function mapper(): MessageMapper {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('setMaxResults')->willReturnCallback(function (?int $max) use ($qb) {
			$this->paging['max'] = $max;
			return $qb;
		});
		$qb->method('setFirstResult')->willReturnCallback(function (int $first) use ($qb) {
			$this->paging['first'] = $first;
			return $qb;
		});
		$qb->method('executeQuery')->willReturn($result);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new MessageMapper($db);
	}//end mapper()
}//end class
