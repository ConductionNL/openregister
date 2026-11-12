<?php

/**
 * FlowTimerFireMapperClaimTest: claiming an escalation rung exactly once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowTimerFire;
use OCA\OpenRegister\Db\FlowTimerFireMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowTimerFireMapper
 * @uses   \OCA\OpenRegister\Db\FlowTimerFire
 */
class FlowTimerFireMapperClaimTest extends TestCase {
	use RecordsQueries;

	/**
	 * A mapper whose insert fails with the given reason.
	 *
	 * @param int|null $reason The database exception reason.
	 *
	 * @return FlowTimerFireMapper
	 */
	private function refusingMapper(?int $reason): FlowTimerFireMapper {
		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn($reason);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnSelf();
		$qb->method('setValue')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeStatement')->willThrowException($failure);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new FlowTimerFireMapper($db);
	}//end refusingMapper()

	/**
	 * A ledger row for a rung.
	 *
	 * @return FlowTimerFire
	 */
	private static function fire(): FlowTimerFire {
		$fire = new FlowTimerFire();
		$fire->setTimerUuid('t1');
		$fire->setRungKey('rung-1');

		return $fire;
	}//end fire()

	public function testAFreeRungIsClaimedByInsertingItsLedgerRow(): void {
		$this->lastInsertId = 6;

		$claimed = (new FlowTimerFireMapper($this->recordingConnection()))->claim(self::fire());

		$this->assertSame(6, $claimed?->getId());
		$this->assertContains("setValue(timer_uuid, 't1')", $this->queries[0]);
		$this->assertContains("setValue(rung_key, 'rung-1')", $this->queries[0]);
	}//end testAFreeRungIsClaimedByInsertingItsLedgerRow()

	public function testARungAnotherPassOwnsIsNotClaimedAndNotAnError(): void {
		$this->assertNull($this->refusingMapper(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION)->claim(self::fire()));
	}//end testARungAnotherPassOwnsIsNotClaimedAndNotAnError()

	public function testAnyOtherWriteFailureIsThrown(): void {
		$this->expectException(DbException::class);

		$this->refusingMapper(DbException::REASON_CONNECTION_LOST)->claim(self::fire());
	}//end testAnyOtherWriteFailureIsThrown()
}//end class
