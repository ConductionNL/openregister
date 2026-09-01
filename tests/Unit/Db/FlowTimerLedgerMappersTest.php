<?php

/**
 * The two append-only ledgers' real reads and writes under the harness:
 * insert stamps and guards, findByTimer filters by timer, and the history
 * read keeps its chronological order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\FlowTimerEventMapper;
use OCA\OpenRegister\Db\FlowTimerFire;
use OCA\OpenRegister\Db\FlowTimerFireMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowTimerFireMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerEventMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerFire
 * @covers \OCA\OpenRegister\Db\FlowTimerEvent
 * @covers \OCA\OpenRegister\Db\FlowTimer
 */
class FlowTimerLedgerMappersTest extends TestCase {
	use FluentQueryBuilderTrait;

	public function testTheFireLedgerInsertsWithAStampAndReadsByTimer(): void {
		$mapper = new FlowTimerFireMapper(db: $this->connectionWith(affectedRows: 1));
		$fire = new FlowTimerFire();
		$fire->setTimerUuid('t-1');
		$fire->setRungKey('preBreach:7:calendarDays');
		$inserted = $mapper->claim(fire: $fire);
		self::assertNotNull($inserted->getCreated());
		self::assertSame(77, $inserted->getId());

		$row = ['id' => 5, 'timer_uuid' => 't-1', 'rung_key' => 'slaBreached:0', 'fired_at' => '2026-10-27 09:00:00', 'recipient_roles' => '["handler"]', 'inherited' => 1, 'created' => '2026-10-27 09:00:00'];
		$mapper = new FlowTimerFireMapper(db: $this->connectionWith(rows: [$row]));
		$fires = $mapper->findByTimer(timerUuid: 't-1');
		self::assertCount(1, $fires);
		self::assertSame(['handler'], $fires[0]->getRecipientRoles());
		self::assertTrue($fires[0]->getInherited());
		self::assertTrue($this->saw('expr.eq', 'timer_uuid'));

		$this->expectException(InvalidArgumentException::class);
		$mapper->insert(new FlowTimer());
	}//end testTheFireLedgerInsertsWithAStampAndReadsByTimer()

	public function testTheHistoryInsertsWithAStampAndReadsOldestFirst(): void {
		$mapper = new FlowTimerEventMapper(db: $this->connectionWith(affectedRows: 1));
		$event = new FlowTimerEvent();
		$event->setTimerUuid('t-1');
		$event->setType(FlowTimerEvent::TYPE_ARMED);
		self::assertNotNull($mapper->insert($event)->getCreated());

		$event2 = new FlowTimerEvent();
		$event2->setTimerUuid('t-1');
		$event2->setType(FlowTimerEvent::TYPE_SUSPENDED);
		$event2->setCreated(new DateTime('2026-01-01'));
		self::assertSame('2026-01-01', $mapper->insert($event2)->getCreated()->format('Y-m-d'));

		$row = ['id' => 6, 'timer_uuid' => 't-1', 'type' => 'suspended', 'actor' => 'bob', 'basis' => 'Awb 4:15', 'created' => '2026-09-20 10:00:00'];
		$mapper = new FlowTimerEventMapper(db: $this->connectionWith(rows: [$row]));
		$events = $mapper->findByTimer(timerUuid: 't-1');
		self::assertSame('Awb 4:15', $events[0]->getBasis());
		self::assertTrue($this->saw('orderBy', 'created'));
		self::assertTrue($this->saw('addOrderBy', 'id'));

		$this->expectException(InvalidArgumentException::class);
		$mapper->insert(new FlowTimerFire());
	}//end testTheHistoryInsertsWithAStampAndReadsOldestFirst()
}//end class
