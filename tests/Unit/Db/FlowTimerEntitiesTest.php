<?php

/**
 * The timer entities: state vocabulary, no overdue anywhere in the
 * serialisation, and the append-only mappers refusing update and delete.
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
use LogicException;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\FlowTimerEventMapper;
use OCA\OpenRegister\Db\FlowTimerFire;
use OCA\OpenRegister\Db\FlowTimerFireMapper;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowTimer
 * @covers \OCA\OpenRegister\Db\FlowTimerFire
 * @covers \OCA\OpenRegister\Db\FlowTimerEvent
 * @covers \OCA\OpenRegister\Db\FlowTimerFireMapper
 * @covers \OCA\OpenRegister\Db\FlowTimerEventMapper
 * @covers \OCA\OpenRegister\Event\FlowTimerFiredEvent
 */
class FlowTimerEntitiesTest extends TestCase {

	public function testTimerStatesPurposesAndOpenness(): void {
		$timer = new FlowTimer();
		$timer->setState(FlowTimer::STATE_ARMED);
		self::assertTrue($timer->isOpen());
		$timer->setState(FlowTimer::STATE_SUSPENDED);
		self::assertTrue($timer->isOpen());
		foreach (FlowTimer::TERMINAL_STATES as $state) {
			$timer->setState($state);
			self::assertFalse($timer->isOpen(), $state);
		}

		self::assertNotContains('overdue', FlowTimer::STATES, 'no overdue state value exists');
		self::assertSame(['due', 'expiry'], FlowTimer::PURPOSES);
		self::assertSame(['none', 'servicenorm', 'wettelijk'], FlowTimer::LEGAL_EFFECTS);
	}//end testTimerStatesPurposesAndOpenness()

	public function testEnforcingNeedsAnExpiryPurposeAndAnOutcome(): void {
		$timer = new FlowTimer();
		$timer->setPurpose(FlowTimer::PURPOSE_DUE);
		$timer->setOnExpiry('skip');
		self::assertFalse($timer->isEnforcing());
		$timer->setPurpose(FlowTimer::PURPOSE_EXPIRY);
		self::assertTrue($timer->isEnforcing());
		$timer->setOnExpiry(null);
		self::assertFalse($timer->isEnforcing());
	}//end testEnforcingNeedsAnExpiryPurposeAndAnOutcome()

	public function testSerialisationCarriesNoOverdueField(): void {
		$timer = new FlowTimer();
		$timer->setUuid('t-1');
		$timer->setAnchorAt(new DateTime('2026-09-01 10:00:00'));
		$timer->setFireAt(null);
		$json = $timer->jsonSerialize();
		self::assertSame('t-1', $json['uuid']);
		self::assertSame('2026-09-01T10:00:00+00:00', substr($json['anchorAt'], 0, 19) . substr($json['anchorAt'], 19));
		self::assertNull($json['fireAt']);
		foreach (array_keys($json) as $key) {
			self::assertStringNotContainsStringIgnoringCase('overdue', (string)$key);
		}

		$fire = new FlowTimerFire();
		$fire->setRungKey('preBreach:14:calendarDays');
		$fire->setFiredAt(new DateTime('2026-09-01 10:00:00'));
		self::assertSame('preBreach:14:calendarDays', $fire->jsonSerialize()['rungKey']);
		self::assertNotNull($fire->jsonSerialize()['firedAt']);

		$event = new FlowTimerEvent();
		$event->setType(FlowTimerEvent::TYPE_SUSPENDED);
		$event->setBasis('Awb 4:15');
		self::assertSame('Awb 4:15', $event->jsonSerialize()['basis']);
		self::assertNull($event->jsonSerialize()['created']);
	}//end testSerialisationCarriesNoOverdueField()

	public function testTheFireLedgerClaimLosesQuietlyOnTheUniqueIndexAndRethrowsAnythingElse(): void {
		$unique = $this->createMock(DbException::class);
		$unique->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$other = $this->createMock(DbException::class);
		$other->method('getReason')->willReturn(DbException::REASON_CONNECTION_LOST);

		$mapper = new class($this->createMock(IDBConnection::class), $unique, $other) extends FlowTimerFireMapper {
			public int $calls = 0;

			public function __construct(IDBConnection $db, private readonly DbException $unique, private readonly DbException $other) {
				parent::__construct(db: $db);
			}

			public function insert(Entity $entity): FlowTimerFire {
				$this->calls++;
				if ($this->calls === 1) {
					return $entity;
				}

				if ($this->calls === 2) {
					throw $this->unique;
				}

				throw $this->other;
			}
		};

		$fire = new FlowTimerFire();
		$fire->setTimerUuid('t-1');
		$fire->setRungKey('k');
		self::assertSame($fire, $mapper->claim(fire: $fire), 'the first insert wins the claim');
		self::assertNull($mapper->claim(fire: $fire), 'a duplicate key means another pass owns the rung');
		$this->expectException(DbException::class);
		$mapper->claim(fire: $fire);
	}//end testTheFireLedgerClaimLosesQuietlyOnTheUniqueIndexAndRethrowsAnythingElse()

	public function testTheLedgersAreAppendOnly(): void {
		$db = $this->createMock(IDBConnection::class);
		$fires = new FlowTimerFireMapper(db: $db);
		$events = new FlowTimerEventMapper(db: $db);
		$fire = new FlowTimerFire();
		$event = new FlowTimerEvent();

		foreach ([[$fires, $fire], [$events, $event]] as [$mapper, $row]) {
			foreach (['update', 'delete'] as $verb) {
				try {
					$mapper->$verb($row);
					self::fail($verb . ' was accepted');
				} catch (LogicException $refused) {
					self::assertStringContainsString('append-only', $refused->getMessage());
				}
			}
		}
	}//end testTheLedgersAreAppendOnly()

	public function testTheFiredEventCarriesTheTransitionAndItsAddressees(): void {
		$timer = new FlowTimer();
		$timer->setUuid('t-1');
		$event = new FlowTimerFiredEvent(
			timer: $timer,
			kind: FlowTimerFiredEvent::KIND_RUNG,
			transition: 'escalation:preBreach:7:calendarDays',
			rungKey: 'preBreach:7:calendarDays',
			recipients: [['type' => 'group', 'id' => 'g', 'role' => 'handler']],
			priority: 'medium',
			message: 'termijn-7d'
		);
		self::assertSame($timer, $event->getTimer());
		self::assertSame('rung', $event->getKind());
		self::assertSame('escalation:preBreach:7:calendarDays', $event->getTransition());
		self::assertSame('preBreach:7:calendarDays', $event->getRungKey());
		self::assertSame('g', $event->getRecipients()[0]['id']);
		self::assertSame('medium', $event->getPriority());
		self::assertSame('termijn-7d', $event->getMessage());
	}//end testTheFiredEventCarriesTheTransitionAndItsAddressees()
}//end class
